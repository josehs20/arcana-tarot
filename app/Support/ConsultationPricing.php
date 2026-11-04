<?php

namespace App\Support;

use App\Enums\ConsultationModality;
use App\Models\Setting;

/**
 * Preço público da consulta por minuto, por modalidade.
 *
 * A regra financeira existente é preservada: o preço por minuto é
 * `creditos por minuto × valor do credito`, ambos configurados pela
 * administracao. Cada modalidade usa a sua propria tarifa
 * (`rate_credits_per_minute_text|audio|video`), com fallback para a chave
 * historica `rate_credits_per_minute` quando a tarifa especifica nao existe.
 *
 * Nenhum preco e recebido do navegador: todo valor aplicado vem daqui e e
 * congelado em snapshots no aceite da consulta.
 */
final class ConsultationPricing
{
    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function rateCreditsPerMinute(?ConsultationModality $modality = null): ?int
    {
        $modality ??= ConsultationModality::Text;

        $rate = $this->integer($this->rateKey($modality))
            ?? $this->integer('rate_credits_per_minute');

        return $rate !== null && $rate > 0 ? $rate : null;
    }

    public function creditUnitPriceCents(): ?int
    {
        $unit = $this->integer('credit_unit_price_cents');

        return $unit !== null && $unit > 0 ? $unit : null;
    }

    public function pricePerMinuteCents(?ConsultationModality $modality = null): ?int
    {
        $rate = $this->rateCreditsPerMinute($modality);
        $unit = $this->creditUnitPriceCents();

        if ($rate === null || $unit === null) {
            return null;
        }

        return $rate * $unit;
    }

    /**
     * Minutos minimos exigidos no aceite. Preferencia para o setting
     * administrativo e fallback para config/consultation.php.
     */
    public function minimumBalanceMinutes(): int
    {
        $minutes = $this->integer('minimum_balance_minutes')
            ?? (int) config('consultation.minimum_balance_minutes', 1);

        return max(1, $minutes);
    }

    /**
     * Saldo minimo em centavos para iniciar uma consulta na modalidade.
     */
    public function minimumBalanceCents(?ConsultationModality $modality = null): ?int
    {
        $price = $this->pricePerMinuteCents($modality);

        return $price === null ? null : $price * $this->minimumBalanceMinutes();
    }

    /**
     * Ex.: "R$ 1,00". Retorna null quando as configuracoes nao estao definidas.
     */
    public function pricePerMinuteLabel(?ConsultationModality $modality = null): ?string
    {
        $cents = $this->pricePerMinuteCents($modality);

        return $cents === null ? null : Money::format($cents);
    }

    public function isPriced(?ConsultationModality $modality = null): bool
    {
        return $this->pricePerMinuteCents($modality) !== null;
    }

    /**
     * @return array<string, int|null>
     */
    public function ratesByModality(): array
    {
        $rates = [];

        foreach (ConsultationModality::cases() as $modality) {
            $rates[$modality->value] = $this->rateCreditsPerMinute($modality);
        }

        return $rates;
    }

    /**
     * @return array<string, int|null>
     */
    public function pricesByModality(): array
    {
        $prices = [];

        foreach (ConsultationModality::cases() as $modality) {
            $prices[$modality->value] = $this->pricePerMinuteCents($modality);
        }

        return $prices;
    }

    /**
     * @return array<string, string|null>
     */
    public function labelsByModality(): array
    {
        $labels = [];

        foreach (ConsultationModality::cases() as $modality) {
            $labels[$modality->value] = $this->pricePerMinuteLabel($modality);
        }

        return $labels;
    }

    public function rateKey(ConsultationModality $modality): string
    {
        return 'rate_credits_per_minute_'.$modality->value;
    }

    private function integer(string $key): ?int
    {
        if ($this->settings === null) {
            $keys = [
                'rate_credits_per_minute',
                'credit_unit_price_cents',
                'minimum_balance_minutes',
            ];

            foreach (ConsultationModality::cases() as $modality) {
                $keys[] = $this->rateKey($modality);
            }

            $this->settings = Setting::query()
                ->whereIn('key', $keys)
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
