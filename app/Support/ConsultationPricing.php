<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Parâmetros globais do crédito.
 *
 * O preço por minuto não é mais definido pela plataforma: cada atendente tem o
 * seu próprio preço por modalidade em `attendant_modality_prices`
 * (`AttendantProfile::priceForModality()`). Este serviço cuida apenas do que
 * continua global — o valor do crédito e o saldo mínimo exigido no aceite — e
 * converte o preço do atendente no saldo necessário para iniciar a consulta.
 *
 * Nenhum preço vem do navegador: o servidor resolve o valor no perfil do
 * atendente e congela o snapshot no aceite.
 */
final class ConsultationPricing
{
    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function creditUnitPriceCents(): ?int
    {
        $unit = $this->integer('credit_unit_price_cents');

        return $unit !== null && $unit > 0 ? $unit : null;
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
     * Saldo mínimo em centavos para iniciar uma consulta com este preço por
     * minuto (`preço do atendente × minutos mínimos`).
     */
    public function minimumBalanceCentsFor(int $pricePerMinuteCents): int
    {
        return max(0, $pricePerMinuteCents) * $this->minimumBalanceMinutes();
    }

    /**
     * Ex.: "R$ 2,00".
     */
    public function minimumBalanceLabelFor(int $pricePerMinuteCents): string
    {
        return Money::format($this->minimumBalanceCentsFor($pricePerMinuteCents));
    }

    private function integer(string $key): ?int
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', ['credit_unit_price_cents', 'minimum_balance_minutes'])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
