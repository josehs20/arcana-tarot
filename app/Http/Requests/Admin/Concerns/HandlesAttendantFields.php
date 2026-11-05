<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\ConsultationModality;
use App\Support\PhoneNumber;
use App\Support\Rate;
use Closure;
use Illuminate\Support\Str;

/**
 * Campos compartilhados entre o cadastro de atendente e a edição de usuário:
 * telefone, especialidades, comissão e preço por minuto.
 *
 * Converte a entrada do formulário para o formato persistido, para que as duas
 * telas gravem exatamente o mesmo tipo de dado (telefone sem máscara,
 * especialidades como lista, comissão em basis points, preço em centavos).
 */
trait HandlesAttendantFields
{
    /**
     * Telefone com DDD e sem máscara; null quando o campo está vazio.
     */
    protected function normalizedPhoneValue(): ?string
    {
        $phone = PhoneNumber::normalize($this->input('phone'));

        return $phone === '' ? null : $phone;
    }

    /**
     * Lista de especialidades digitada como texto separado por vírgulas.
     *
     * @return array<int, string>
     */
    protected function specialtiesFromInput(): array
    {
        $raw = $this->input('specialties');

        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_scalar($raw)) {
            $items = explode(',', (string) $raw);
        } else {
            return [];
        }

        return collect($items)
            ->filter(static fn ($item): bool => is_scalar($item))
            ->map(static fn ($item): string => Str::squish((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * Taxa de comissão digitada em percentual, convertida para basis points.
     */
    public function commissionRateBps(): ?int
    {
        $value = $this->input('commission_rate');

        return Rate::parsePercentToBps(is_scalar($value) ? (string) $value : null);
    }

    /**
     * Percentual aceito entre 0% e 100%, com vírgula ou ponto decimal.
     */
    protected function commissionRateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Rate::parsePercentToBps(is_scalar($value) ? (string) $value : null) === null) {
                $fail('Informe a comissão entre 0% e 100% (ex.: 30 ou 25,5).');
            }
        };
    }

    /**
     * Preço por minuto informado para cada modalidade, em centavos.
     *
     * Com `$keepBlanks`, modalidades deixadas em branco entram como null para
     * que a edição saiba que o preço deve ser removido.
     *
     * @return array<string, int|null>
     */
    public function priceCents(bool $keepBlanks = false): array
    {
        $input = $this->input('prices');
        $prices = [];

        foreach (ConsultationModality::cases() as $modality) {
            $raw = is_array($input) ? ($input[$modality->value] ?? null) : null;

            if ($raw === null || $raw === '') {
                if ($keepBlanks) {
                    $prices[$modality->value] = null;
                }

                continue;
            }

            $prices[$modality->value] = (int) $raw;
        }

        return $prices;
    }
}
