<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Faixa de valores aceita nas recargas de carteira.
 *
 * A administração define o mínimo e o máximo em /admin/financeiro/pacotes.
 * Eles ficam nos settings `payment_min_amount_cents` e
 * `payment_max_amount_cents` (sempre em centavos) e valem para a recarga livre
 * do sandbox e para os pacotes de créditos do checkout, que só aparecem quando
 * o total cai dentro da faixa.
 *
 * Enquanto a administração não salvar nada, `config('payments.*_amount_cents')`
 * segue como fallback de instalação (valores vindos do .env).
 */
final class PaymentLimits
{
    public const MIN_SETTING_KEY = 'payment_min_amount_cents';

    public const MAX_SETTING_KEY = 'payment_max_amount_cents';

    /** Teto defensivo para o máximo configurável (R$ 1.000.000,00). */
    public const MAX_LIMIT_CENTS = 100_000_000;

    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function minimumCents(): int
    {
        return max(1, $this->stored(self::MIN_SETTING_KEY) ?? (int) config('payments.min_amount_cents', 100));
    }

    public function maximumCents(): int
    {
        $maximum = $this->stored(self::MAX_SETTING_KEY) ?? (int) config('payments.max_amount_cents', self::MAX_LIMIT_CENTS);

        return max($this->minimumCents(), $maximum);
    }

    public function minimumLabel(): string
    {
        return Money::format($this->minimumCents());
    }

    public function maximumLabel(): string
    {
        return Money::format($this->maximumCents());
    }

    /**
     * Substitui a faixa de recarga e registra a auditoria (antes/depois).
     */
    public function save(int $minimumCents, int $maximumCents, ?User $actor = null): void
    {
        $before = [
            'min_amount_cents' => $this->minimumCents(),
            'max_amount_cents' => $this->maximumCents(),
        ];

        DB::transaction(function () use ($minimumCents, $maximumCents, $before, $actor): void {
            $values = [
                self::MIN_SETTING_KEY => $minimumCents,
                self::MAX_SETTING_KEY => $maximumCents,
            ];

            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $value, 'type' => 'integer'],
                );
            }

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'action' => 'payment_limits.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'min_amount_cents' => $minimumCents,
                        'max_amount_cents' => $maximumCents,
                    ],
                ],
            ]);
        });

        $this->settings = null;
    }

    private function stored(string $key): ?int
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', [self::MIN_SETTING_KEY, self::MAX_SETTING_KEY])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
