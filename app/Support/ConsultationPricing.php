<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Preço público da consulta por minuto.
 *
 * O valor é sempre calculado no servidor a partir das configurações
 * (`rate_credits_per_minute` × `credit_unit_price_cents`). Não existe
 * preço individual por atendente nesta etapa.
 */
final class ConsultationPricing
{
    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function pricePerMinuteCents(): ?int
    {
        $rate = $this->integer('rate_credits_per_minute');
        $unit = $this->integer('credit_unit_price_cents');

        if ($rate === null || $unit === null || $rate <= 0 || $unit <= 0) {
            return null;
        }

        return $rate * $unit;
    }

    /**
     * Ex.: "R$ 1,00". Retorna null quando as configurações não estão definidas.
     */
    public function pricePerMinuteLabel(): ?string
    {
        $cents = $this->pricePerMinuteCents();

        if ($cents === null) {
            return null;
        }

        $reais = intdiv($cents, 100);
        $centavos = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return 'R$ '.number_format($reais, 0, ',', '.').','.$centavos;
    }

    private function integer(string $key): ?int
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', ['rate_credits_per_minute', 'credit_unit_price_cents'])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
