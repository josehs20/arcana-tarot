<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pacotes de créditos oferecidos no checkout.
 *
 * A administração define o catálogo em /admin/financeiro. Ele é persistido no
 * setting `credit_packages` (JSON) e passa a ser a fonte principal; quando o
 * setting não existe (instalação nova) ou está corrompido, o fallback é
 * `config('payments.packages')`.
 *
 * Os valores continuam sendo derivados no servidor: o catálogo guarda apenas a
 * quantidade de créditos (e um rótulo opcional) e o preço unitário vigente
 * define o valor cobrado. Pacotes fora da faixa mínima ou máxima de recarga
 * aparecem como indisponíveis para a administração em vez de sumirem em
 * silêncio do checkout.
 */
final class CreditPackages
{
    public const SETTING_KEY = 'credit_packages';

    public const MAX_PACKAGES = 20;

    public const MAX_LABEL_LENGTH = 60;

    /** Limite defensivo para a quantidade de créditos de um único pacote. */
    public const MAX_CREDITS = 100_000;

    public function __construct(
        private readonly ConsultationPricing $pricing,
        private readonly PaymentLimits $limits,
    ) {}

    /**
     * Catálogo configurado, com preço derivado e faixa de disponibilidade.
     *
     * @return list<array{key: string, credits: int, label: string, custom_label: bool, amount_cents: int|null, available: bool}>
     */
    public function configured(): array
    {
        $unitPriceCents = $this->pricing->creditUnitPriceCents();
        $minimum = $this->minimumAmountCents();
        $maximum = $this->maximumAmountCents();

        $packages = [];

        foreach ($this->catalog() as $entry) {
            $credits = $entry['credits'];
            $amountCents = $unitPriceCents === null ? null : $credits * $unitPriceCents;

            $packages[] = [
                'key' => $entry['key'],
                'credits' => $credits,
                'label' => $entry['label'] ?? $this->defaultLabel($credits),
                'custom_label' => $entry['label'] !== null,
                'amount_cents' => $amountCents,
                'available' => $amountCents !== null && $amountCents >= $minimum && $amountCents <= $maximum,
            ];
        }

        return $packages;
    }

    /**
     * Pacotes efetivamente ofertados ao cliente (indexados pela chave).
     *
     * @return array<string, array{key: string, credits: int, amount_cents: int, credit_unit_price_cents: int, label: string}>
     */
    public function available(): array
    {
        $unitPriceCents = $this->pricing->creditUnitPriceCents();

        if ($unitPriceCents === null || $unitPriceCents < 1) {
            return [];
        }

        $packages = [];

        foreach ($this->configured() as $package) {
            if (! $package['available'] || $package['amount_cents'] === null) {
                continue;
            }

            $packages[$package['key']] = [
                'key' => $package['key'],
                'credits' => $package['credits'],
                'amount_cents' => $package['amount_cents'],
                'credit_unit_price_cents' => $unitPriceCents,
                'label' => $package['label'],
            ];
        }

        return $packages;
    }

    /**
     * @return array{key: string, credits: int, amount_cents: int, credit_unit_price_cents: int, label: string}|null
     */
    public function find(string $key): ?array
    {
        return $this->available()[trim($key)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->available());
    }

    /**
     * Catálogo bruto (chave, créditos e rótulo opcional) usado pela
     * administração. Sempre ordenado por quantidade de créditos.
     *
     * @return list<array{key: string, credits: int, label: string|null}>
     */
    public function catalog(): array
    {
        $packages = $this->storedCatalog() ?? $this->configCatalog();

        usort($packages, fn (array $first, array $second): int => $first['credits'] <=> $second['credits']);

        return $packages;
    }

    /**
     * Substitui o catálogo configurado pela administração.
     *
     * A chave interna de cada pacote é derivada da quantidade de créditos
     * (`credits_50`, ...), então o histórico de `payments.package_key` continua
     * legível mesmo depois de o catálogo ser reescrito.
     *
     * @param  array<int, array{credits?: mixed, label?: mixed}>  $entries
     * @return list<array{key: string, credits: int, label: string|null}>
     */
    public function save(array $entries, ?User $actor = null): array
    {
        $before = $this->catalog();
        $packages = [];
        $usedKeys = [];

        foreach (array_values($entries) as $entry) {
            $credits = $this->normalizeCredits(is_array($entry) ? ($entry['credits'] ?? null) : null);

            if ($credits === null) {
                continue;
            }

            $key = $this->uniqueKey($credits, $usedKeys);
            $usedKeys[] = $key;

            $packages[] = [
                'key' => $key,
                'credits' => $credits,
                'label' => $this->normalizeLabel(is_array($entry) ? ($entry['label'] ?? null) : null),
            ];
        }

        if ($packages === []) {
            throw new InvalidArgumentException('Informe pelo menos um pacote de créditos.');
        }

        DB::transaction(function () use ($packages, $before, $actor): void {
            Setting::query()->updateOrCreate(
                ['key' => self::SETTING_KEY],
                [
                    'value' => json_encode(
                        array_map(
                            fn (array $package): array => [
                                'credits' => $package['credits'],
                                'label' => $package['label'],
                            ],
                            $packages,
                        ),
                        JSON_UNESCAPED_UNICODE,
                    ),
                    'type' => 'json',
                ],
            );

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'action' => 'credit_packages.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => $packages,
                ],
            ]);
        });

        return $packages;
    }

    public function minimumAmountCents(): int
    {
        return $this->limits->minimumCents();
    }

    public function maximumAmountCents(): int
    {
        return $this->limits->maximumCents();
    }

    /**
     * Quantos pacotes do catálogo estão dentro da faixa de recarga.
     */
    public function availablePackagesCount(): int
    {
        return count($this->available());
    }

    /**
     * @return list<array{key: string, credits: int, label: string|null}>|null
     */
    private function storedCatalog(): ?array
    {
        $raw = Setting::query()->where('key', self::SETTING_KEY)->value('value');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $packages = [];
        $usedKeys = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $credits = $this->normalizeCredits($entry['credits'] ?? null);

            if ($credits === null) {
                continue;
            }

            $key = $this->uniqueKey($credits, $usedKeys);
            $usedKeys[] = $key;

            $packages[] = [
                'key' => $key,
                'credits' => $credits,
                'label' => $this->normalizeLabel($entry['label'] ?? null),
            ];
        }

        return $packages === [] ? null : $packages;
    }

    /**
     * @return list<array{key: string, credits: int, label: string|null}>
     */
    private function configCatalog(): array
    {
        $packages = [];

        foreach ((array) config('payments.packages', []) as $key => $credits) {
            if (! is_string($key) || ! is_int($credits) || $credits < 1 || $credits > self::MAX_CREDITS) {
                continue;
            }

            $packages[] = ['key' => $key, 'credits' => $credits, 'label' => null];
        }

        return $packages;
    }

    private function normalizeCredits(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_CREDITS) {
            return null;
        }

        return $value;
    }

    private function normalizeLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $label = trim($value);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * @param  list<string>  $usedKeys
     */
    private function uniqueKey(int $credits, array $usedKeys): string
    {
        $base = 'credits_'.$credits;
        $key = $base;
        $suffix = 2;

        while (in_array($key, $usedKeys, true)) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function defaultLabel(int $credits): string
    {
        return $credits.' '.($credits === 1 ? 'crédito' : 'créditos');
    }
}
