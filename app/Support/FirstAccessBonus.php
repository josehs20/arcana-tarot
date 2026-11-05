<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bônus de créditos no primeiro acesso do cliente.
 *
 * A concessão é opcional e configurável em /admin/financeiro/bonus. Quando
 * ativa, todo cliente que conclui o primeiro cadastro (e-mail com SMS
 * confirmado ou Google) recebe os créditos uma única vez: a chave de
 * idempotência `first_access_bonus:user:{id}` garante que reenviar o cadastro,
 * repetir o login social ou reprocessar a rotina não credite duas vezes.
 *
 * O valor em centavos é sempre derivado da tarifa vigente do crédito, nunca do
 * formulário, e a operação entra no livro-razão via CreditWalletManager.
 */
final class FirstAccessBonus
{
    public const ENABLED_SETTING_KEY = 'first_access_bonus_enabled';

    public const CREDITS_SETTING_KEY = 'first_access_bonus_credits';

    public const IDEMPOTENCY_PREFIX = 'first_access_bonus:user:';

    /** Teto defensivo para o bônus (mesma ordem de grandeza dos pacotes). */
    public const MAX_CREDITS = 1000;

    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly CreditWalletManager $wallets,
        private readonly ConsultationPricing $pricing,
    ) {}

    public static function idempotencyKeyFor(User $user): string
    {
        return self::IDEMPOTENCY_PREFIX.$user->getKey();
    }

    public function isEnabled(): bool
    {
        $stored = $this->stored(self::ENABLED_SETTING_KEY);

        if ($stored !== null) {
            return (bool) (int) $stored;
        }

        return (bool) config('authentication.first_access_bonus.enabled', false);
    }

    public function credits(): int
    {
        $stored = $this->stored(self::CREDITS_SETTING_KEY);
        $credits = $stored !== null ? (int) $stored : (int) config('authentication.first_access_bonus.credits', 0);

        return max(0, min(self::MAX_CREDITS, $credits));
    }

    /**
     * Valor do bônus em centavos, derivado da tarifa vigente do crédito.
     */
    public function amountCents(): ?int
    {
        $unitPriceCents = $this->pricing->creditUnitPriceCents();
        $credits = $this->credits();

        if ($unitPriceCents === null || $credits < 1) {
            return null;
        }

        return $unitPriceCents * $credits;
    }

    /**
     * Concede o bônus ao cliente, uma única vez.
     *
     * Retorna o lançamento criado ou null quando o bônus está desligado, não há
     * tarifa configurada, o usuário não é cliente ou o bônus já foi concedido.
     */
    public function grantFor(User $user): ?CreditLedgerEntry
    {
        if (! $user->isClient() || ! $this->isEnabled()) {
            return null;
        }

        $credits = $this->credits();
        $amountCents = $this->amountCents();

        if ($credits < 1 || $amountCents === null) {
            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'first_access_bonus.skipped',
                'subject_type' => $user->getMorphClass(),
                'subject_id' => $user->getKey(),
                'metadata' => [
                    'reason' => $credits < 1 ? 'no_credits_configured' : 'credit_unit_price_missing',
                    'credits' => $credits,
                ],
            ]);

            return null;
        }

        $idempotencyKey = self::idempotencyKeyFor($user);

        if (CreditLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($user, $amountCents, $credits, $idempotencyKey): CreditLedgerEntry {
            $entry = $this->wallets->credit($user, $amountCents, $idempotencyKey, $user);

            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'first_access_bonus.granted',
                'subject_type' => $user->getMorphClass(),
                'subject_id' => $user->getKey(),
                'metadata' => [
                    'credits' => $credits,
                    'amount_cents' => $amountCents,
                    'ledger_entry_id' => $entry->getKey(),
                ],
            ]);

            return $entry;
        });
    }

    /**
     * Dados da tela administrativa e do resumo exibido ao lado do bônus.
     *
     * @return array{
     *     enabled: bool,
     *     credits: int,
     *     amount_cents: int|null,
     *     granted_count: int,
     *     granted_cents: int
     * }
     */
    public function summary(): array
    {
        $entries = CreditLedgerEntry::query()
            ->where('idempotency_key', 'like', self::IDEMPOTENCY_PREFIX.'%');

        return [
            'enabled' => $this->isEnabled(),
            'credits' => $this->credits(),
            'amount_cents' => $this->amountCents(),
            'granted_count' => (clone $entries)->count(),
            'granted_cents' => (int) (clone $entries)->sum('amount_cents'),
        ];
    }

    /**
     * Salva a configuração do bônus com auditoria (antes/depois).
     */
    public function save(bool $enabled, int $credits, ?User $actor = null): void
    {
        $credits = max(0, min(self::MAX_CREDITS, $credits));

        $before = [
            'enabled' => $this->isEnabled(),
            'credits' => $this->credits(),
        ];

        DB::transaction(function () use ($enabled, $credits, $before, $actor): void {
            Setting::query()->updateOrCreate(
                ['key' => self::ENABLED_SETTING_KEY],
                ['value' => $enabled ? '1' : '0', 'type' => 'boolean'],
            );

            Setting::query()->updateOrCreate(
                ['key' => self::CREDITS_SETTING_KEY],
                ['value' => (string) $credits, 'type' => 'integer'],
            );

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'action' => 'first_access_bonus.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => ['enabled' => $enabled, 'credits' => $credits],
                ],
            ]);
        });

        $this->settings = null;
    }

    private function stored(string $key): ?string
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', [self::ENABLED_SETTING_KEY, self::CREDITS_SETTING_KEY])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (string) $value : null;
    }
}
