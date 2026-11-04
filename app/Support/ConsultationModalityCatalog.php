<?php

namespace App\Support;

use App\Enums\ConsultationModality;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;

/**
 * Fonte unica das modalidades oferecidas, precos e disponibilidade.
 *
 * Uma modalidade so pode ser contratada quando:
 * - a infraestrutura correspondente esta habilitada (config);
 * - o atendente escolhido a oferece explicitamente;
 * - o atendente possui preco por minuto configurado para a modalidade
 *   (`attendant_modality_prices`).
 *
 * Não existe mais preço global: o valor exibido e cobrado vem sempre do
 * profissional escolhido. A interface usa exatamente a mesma resolucao; o
 * servidor revalida no momento da solicitacao e do aceite.
 */
final class ConsultationModalityCatalog
{
    public function __construct(private readonly ConsultationPricing $pricing) {}

    public function isEnabled(ConsultationModality $modality): bool
    {
        if (! $modality->requiresMedia()) {
            return true;
        }

        return (bool) config('consultation.modalities.'.$modality->value.'.enabled', false);
    }

    /**
     * Preço por minuto em centavos definido pelo atendente para a modalidade.
     */
    public function priceCentsFor(?AttendantProfile $profile, ConsultationModality $modality): ?int
    {
        return $profile?->priceForModality($modality);
    }

    /**
     * Ex.: "R$ 1,50". Retorna null quando o atendente não configurou o preço.
     */
    public function priceLabelFor(?AttendantProfile $profile, ConsultationModality $modality): ?string
    {
        $cents = $this->priceCentsFor($profile, $modality);

        return $cents === null ? null : Money::format($cents);
    }

    public function isAvailableFor(?AttendantProfile $profile, ConsultationModality $modality): bool
    {
        return $this->unavailableReason($profile, $modality) === null;
    }

    /**
     * Menor preco por minuto entre os atendentes disponiveis.
     *
     * Serve apenas como referencia ("a partir de") quando ainda nao existe um
     * atendente escolhido. Cada consulta usa sempre o preco do proprio
     * profissional, congelado no aceite.
     */
    public function startingPriceCents(): ?int
    {
        $cents = AttendantModalityPrice::query()
            ->whereHas('attendantProfile', static fn ($query) => $query->available())
            ->min('price_per_minute_cents');

        return $cents === null ? null : (int) $cents;
    }

    public function startingPriceLabel(): ?string
    {
        $cents = $this->startingPriceCents();

        return $cents === null ? null : Money::format($cents);
    }

    public function unavailableReason(?AttendantProfile $profile, ConsultationModality $modality): ?string
    {
        if (! $this->isEnabled($modality)) {
            return $modality->requiresVideo()
                ? 'Atendimento por video indisponivel no momento.'
                : 'Atendimento por audio indisponivel no momento.';
        }

        if ($profile === null) {
            return 'Escolha um atendente para consultar o preço desta modalidade.';
        }

        if (! $profile->offersModality($modality)) {
            return 'Este atendente nao oferece atendimento por '.$modality->label().'.';
        }

        if ($this->priceCentsFor($profile, $modality) === null) {
            return 'O preco desta modalidade ainda nao foi configurado para este atendente.';
        }

        return null;
    }

    /**
     * @return array<int, array{modality: ConsultationModality, value: string, label: string, description: string, icon: string, price_cents: int|null, price_label: string|null, available: bool, reason: string|null, requires_video: bool}>
     */
    public function options(?AttendantProfile $profile = null): array
    {
        $options = [];

        foreach (ConsultationModality::cases() as $modality) {
            $options[] = [
                'modality' => $modality,
                'value' => $modality->value,
                'label' => $modality->label(),
                'description' => $modality->description(),
                'icon' => $modality->icon(),
                'price_cents' => $this->priceCentsFor($profile, $modality),
                'price_label' => $this->priceLabelFor($profile, $modality),
                'available' => $this->isAvailableFor($profile, $modality),
                'reason' => $this->unavailableReason($profile, $modality),
                'requires_video' => $modality->requiresVideo(),
            ];
        }

        return $options;
    }

    /**
     * Opcoes publicas do diretorio: apenas modalidades oferecidas, precificadas
     * e disponiveis, usadas para exibir precos antes da consulta.
     *
     * @return array<int, array{label: string, value: string, price_label: string|null, price_cents: int|null, icon: string}>
     */
    public function publicOptionsFor(AttendantProfile $profile): array
    {
        $options = [];

        foreach ($this->options($profile) as $option) {
            if (! $option['available']) {
                continue;
            }

            $options[] = [
                'label' => $option['label'],
                'value' => $option['value'],
                'price_label' => $option['price_label'],
                'price_cents' => $option['price_cents'],
                'icon' => $option['icon'],
            ];
        }

        return $options;
    }
}
