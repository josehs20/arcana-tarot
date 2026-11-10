<?php

namespace App\Support;

use App\Enums\EmailRecipientStatus;
use App\Enums\UserRole;
use App\Jobs\SendBulkEmailRecipient;
use App\Models\AuditLog;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Regras do disparo de e-mails em massa (/admin/emails).
 *
 * A tela apenas seleciona usuários e escreve o texto: quem decide a lista final
 * de destinatários, cria a campanha, audita e enfileira os envios é esta classe,
 * para que a regra valha também em jobs e comandos futuros.
 *
 * Cada destinatário gera um job próprio. O job confere o status antes de enviar,
 * então reenviar falhas nunca duplica um e-mail já entregue.
 */
final class BulkEmailDispatcher
{
    /** Teto defensivo por disparo (evita um formulário gigante e abuso de SMTP). */
    public const MAX_RECIPIENTS = 2000;

    public const MAX_SUBJECT_LENGTH = 150;

    public const MAX_BODY_LENGTH = 10000;

    /**
     * Usuários que podem receber e-mail: sempre com endereço cadastrado.
     *
     * @param  array{q?: string|null, role?: string|null, status?: string|null}  $filters
     * @return Builder<User>
     */
    public function recipientsQuery(array $filters = []): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->when(
                in_array($filters['role'] ?? null, array_column(UserRole::cases(), 'value'), true),
                fn (Builder $query): Builder => $query->where('role', $filters['role']),
            )
            ->when(
                in_array($filters['status'] ?? null, ['active', 'inactive'], true),
                fn (Builder $query): Builder => $query->where('is_active', $filters['status'] === 'active'),
            )
            ->orderBy('name');
    }

    /**
     * Cria a campanha, registra os destinatários e enfileira os envios.
     *
     * @param  array<int, int>  $userIds
     * @param  array{q?: string|null, role?: string|null, status?: string|null}  $filters
     *
     * @throws ValidationException Quando nenhum usuário selecionado tem e-mail válido.
     */
    public function create(User $actor, string $subject, string $body, array $userIds, array $filters = []): EmailCampaign
    {
        $recipients = $this->selectedRecipients($userIds);

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'Selecione ao menos um usuário com e-mail válido para receber o disparo.',
            ]);
        }

        $campaign = DB::transaction(function () use ($actor, $subject, $body, $recipients, $filters): EmailCampaign {
            $campaign = EmailCampaign::query()->create([
                'created_by' => $actor->getKey(),
                'subject' => trim($subject),
                'body' => trim($body),
                'recipients_count' => $recipients->count(),
                'filters' => $this->normalizeFilters($filters),
            ]);

            foreach ($recipients as $user) {
                $campaign->recipients()->create([
                    'user_id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => (string) $user->email,
                    'status' => EmailRecipientStatus::Pending,
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'email_campaign.created',
                'subject_type' => $campaign->getMorphClass(),
                'subject_id' => $campaign->getKey(),
                'metadata' => [
                    'subject' => $campaign->subject,
                    'recipients_count' => $campaign->recipients_count,
                    'filters' => $campaign->filters,
                ],
            ]);

            return $campaign;
        });

        // Fora da transação: se a fila for síncrona (testes), o job já encontra
        // os destinatários gravados.
        $this->queueRecipients($campaign);

        return $campaign;
    }

    /**
     * Reenfileira apenas os destinatários que falharam.
     *
     * @return int Quantidade de falhas reenfileiradas.
     *
     * @throws ValidationException Quando a campanha não tem falhas para reenviar.
     */
    public function resendFailed(EmailCampaign $campaign, User $actor): int
    {
        $recipientIds = DB::transaction(function () use ($campaign, $actor): Collection {
            $recipientIds = $campaign->recipients()
                ->where('status', EmailRecipientStatus::Failed->value)
                ->pluck('id');

            if ($recipientIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'campaign' => 'Este disparo não possui falhas para reenviar.',
                ]);
            }

            EmailCampaignRecipient::query()
                ->whereIn('id', $recipientIds)
                ->update([
                    'status' => EmailRecipientStatus::Pending->value,
                    'error' => null,
                    'sent_at' => null,
                ]);

            $campaign->refreshCounters();

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'email_campaign.failures_requeued',
                'subject_type' => $campaign->getMorphClass(),
                'subject_id' => $campaign->getKey(),
                'metadata' => [
                    'subject' => $campaign->subject,
                    'requeued_count' => $recipientIds->count(),
                ],
            ]);

            return $recipientIds;
        });

        $campaign->recipients()
            ->whereIn('id', $recipientIds)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $recipientId): mixed => SendBulkEmailRecipient::dispatch($recipientId));

        return $recipientIds->count();
    }

    /**
     * Usuários selecionados, sem e-mails inválidos nem caixas repetidas.
     *
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, User>
     */
    private function selectedRecipients(array $userIds): Collection
    {
        $ids = collect($userIds)->map(fn (int|string $id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique(fn (User $user): string => Str::lower(trim((string) $user->email)))
            ->values();
    }

    /**
     * Enfileira um job por destinatário ainda pendente.
     */
    private function queueRecipients(EmailCampaign $campaign): void
    {
        $campaign->recipients()
            ->where('status', EmailRecipientStatus::Pending->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $recipientId): mixed => SendBulkEmailRecipient::dispatch($recipientId));
    }

    /**
     * Filtros aplicados na seleção, guardados para leitura na auditoria.
     *
     * @param  array{q?: string|null, role?: string|null, status?: string|null}  $filters
     * @return array<string, string>|null
     */
    private function normalizeFilters(array $filters): ?array
    {
        $normalized = [];

        foreach (['q', 'role', 'status'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized === [] ? null : $normalized;
    }
}
