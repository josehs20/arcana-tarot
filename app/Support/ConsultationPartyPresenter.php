<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Cartões de identificação das partes do atendimento.
 *
 * A tela dedicada mostra "quem está do outro lado": o cliente vê o cartão do
 * atendente e o atendente vê o cartão do cliente. Todos os números (idade,
 * tempo estimado de saldo, valor cobrado, comissão) vêm do servidor — o
 * navegador só os substitui quando `consultations.metrics` é atualizado.
 *
 * Dados de contato (e-mail, telefone) nunca entram no cartão: o chat bloqueia a
 * troca de contatos e o cartão não pode ser uma porta lateral para isso.
 */
final class ConsultationPartyPresenter
{
    /**
     * @return array{
     *     client: array<string, mixed>,
     *     attendant: array<string, mixed>
     * }
     */
    public static function toArray(Consultation $consultation, User $viewer): array
    {
        $consultation->loadMissing([
            'client.wallet',
            'attendant.wallet',
            'attendant.attendantProfile',
        ]);

        return [
            'client' => self::clientCard($consultation),
            'attendant' => self::attendantCard($consultation, $viewer),
        ];
    }

    /**
     * @return array{
     *     role: string,
     *     role_label: string,
     *     name: string,
     *     initials: string,
     *     photo_url: string|null,
     *     birth_date_label: string,
     *     age_label: string|null,
     *     member_since_label: string,
     *     consultations_count: int,
     *     consultations_label: string,
     *     available_minutes: int|null,
     *     available_label: string|null,
     *     available_explanation: string|null,
     *     charged_label: string,
     *     price_per_minute_label: string|null
     * }
     */
    private static function clientCard(Consultation $consultation): array
    {
        $client = $consultation->client;
        $consultationsCount = (int) $client->clientConsultations()->count();
        $available = ConsultationMetricsPresenter::availableTime($consultation);

        return [
            'role' => 'client',
            'role_label' => 'Cliente',
            'name' => $client->name,
            'initials' => self::initials($client->name),
            'photo_url' => null,
            'birth_date_label' => $client->birthDateLabel() ?? 'Não informado',
            'age_label' => $client->ageLabel(),
            'member_since_label' => $client->created_at?->format('m/Y') ?? '—',
            'consultations_count' => $consultationsCount,
            'consultations_label' => $consultationsCount.' '.($consultationsCount === 1 ? 'atendimento' : 'atendimentos'),
            'available_minutes' => $available['minutes'],
            'available_label' => $available['label'],
            'available_explanation' => $available['explanation'],
            'charged_label' => Money::format($consultation->totalChargedCents()),
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attendantCard(Consultation $consultation, User $viewer): array
    {
        $attendant = $consultation->attendant;
        $profile = $attendant->attendantProfile;

        /** @var array<string, mixed> $specialties */
        $specialties = $profile?->specialties ?? [];

        return [
            'role' => 'attendant',
            'role_label' => 'Atendente',
            'name' => $profile?->display_name ?? $attendant->name,
            'initials' => $profile?->initials() ?? self::initials($attendant->name),
            'photo_url' => $profile?->photoUrl(),
            'bio' => $profile?->bio,
            'specialties' => array_values(array_filter(
                array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $specialties),
                static fn (string $item): bool => $item !== '',
            )),
            'birth_date_label' => $attendant->birthDateLabel() ?? 'Não informado',
            'age_label' => $attendant->ageLabel(),
            'member_since_label' => $profile?->created_at?->format('m/Y') ?? $attendant->created_at?->format('m/Y') ?? '—',
            'consultations_count' => (int) $attendant->attendantConsultations()->count(),
            'consultations_label' => null,
            'available_minutes' => null,
            'available_label' => null,
            'available_explanation' => null,
            'charged_label' => Money::format($consultation->totalChargedCents()),
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            'commission_rate_label' => $consultation->isAttendant($viewer) && $consultation->commission_rate_bps !== null
                ? Rate::formatBps($consultation->commission_rate_bps)
                : null,
        ];
    }

    private static function initials(string $name): string
    {
        $initials = Str::of($name)
            ->squish()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '★';
    }
}
