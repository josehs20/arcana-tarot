<?php

namespace App\Models;

use App\Enums\EmailRecipientStatus;
use Database\Factories\EmailCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Disparo de e-mail em massa feito pelo painel administrativo.
 *
 * Guarda o assunto e o corpo exatamente como foram enviados (snapshot) e os
 * contadores derivados dos destinatários. O body aceita os marcadores
 * `{{nome}}`, `{{email}}` e `{{link}}`, substituídos no momento do envio.
 */
#[Fillable(['created_by', 'subject', 'body', 'recipients_count', 'sent_count', 'failed_count', 'filters'])]
class EmailCampaign extends Model
{
    /** @use HasFactory<EmailCampaignFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    public function pendingCount(): int
    {
        return $this->recipients()
            ->where('status', EmailRecipientStatus::Pending->value)
            ->count();
    }

    /**
     * Todos os destinatários já saíram da fila (enviados ou com falha definitiva).
     *
     * Usa os contadores da própria linha (mantidos por refreshCounters) para não
     * disparar uma contagem por item da lista de histórico.
     */
    public function isCompleted(): bool
    {
        return $this->sent_count + $this->failed_count >= $this->recipients_count;
    }

    public function hasFailures(): bool
    {
        return $this->failed_count > 0;
    }

    public function statusLabel(): string
    {
        if (! $this->isCompleted()) {
            return 'Em envio';
        }

        return $this->hasFailures() ? 'Concluído com falhas' : 'Concluído';
    }

    public function statusBadge(): string
    {
        if (! $this->isCompleted()) {
            return 'pending';
        }

        return $this->hasFailures() ? 'failed' : 'sent';
    }

    /**
     * Recalcula os contadores a partir dos destinatários.
     *
     * Os jobs atualizam o status de cada linha e chamam este método em vez de
     * incrementar números: assim reenviar uma falha (failed → pending → sent)
     * mantém o resumo do disparo correto, mesmo com tentativas repetidas.
     */
    public function refreshCounters(): void
    {
        /** @var Collection<string, int|string> $counts */
        $counts = $this->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->forceFill([
            'recipients_count' => (int) $counts->sum(),
            'sent_count' => (int) ($counts[EmailRecipientStatus::Sent->value] ?? 0),
            'failed_count' => (int) ($counts[EmailRecipientStatus::Failed->value] ?? 0),
        ])->save();
    }
}
