<?php

namespace App\Support;

use App\Enums\ConsultationModality;
use App\Models\AttendantProfile;

/**
 * Fonte unica das modalidades oferecidas, precos e disponibilidade.
 *
 * Uma modalidade so pode ser contratada quando:
 * - a infraestrutura correspondente esta habilitada (config);
 * - existe preco configurado pela administracao;
 * - o atendente escolhido a oferece explicitamente.
 *
 * A interface usa exatamente a mesma resolucao; o servidor revalida no
 * momento da solicitacao e do aceite.
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

    public function isAvailableFor(?AttendantProfile $profile, ConsultationModality $modality): bool
    {
        if (! $this->isEnabled($modality) || ! $this->pricing->isPriced($modality)) {
            return false;
        }

        return $profile === null || $profile->offersModality($modality);
    }

    public function unavailableReason(?AttendantProfile $profile, ConsultationModality $modality): ?string
    {
        if (! $this->isEnabled($modality)) {
            return $modality->requiresVideo()
                ? 'Atendimento por video indisponivel no momento.'
                : 'Atendimento por audio indisponivel no momento.';
        }

        if (! $this->pricing->isPriced($modality)) {
            return 'O preco desta modalidade ainda nao foi configurado.';
        }

        if ($profile !== null && ! $profile->offersModality($modality)) {
            return 'Este atendente nao oferece atendimento por '.$modality->label().'.';
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
                'price_cents' => $this->pricing->pricePerMinuteCents($modality),
                'price_label' => $this->pricing->pricePerMinuteLabel($modality),
                'available' => $this->isAvailableFor($profile, $modality),
                'reason' => $this->unavailableReason($profile, $modality),
                'requires_video' => $modality->requiresVideo(),
            ];
        }

        return $options;
    }

    /**
     * Opcoes publicas do diretorio: apenas modalidades oferecidas e
     * disponiveis, usadas para exibir precos antes da consulta.
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
