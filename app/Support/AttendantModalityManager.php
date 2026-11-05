<?php

namespace App\Support;

use App\Enums\ConsultationModality;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modalidades oferecidas e preço por minuto de cada atendente.
 *
 * Duas telas escrevem aqui: a página de modalidades, que exige preço para toda
 * modalidade marcada, e a edição do usuário, onde limpar o preço apenas
 * desabilita a modalidade. Em ambas, texto continua sendo a base e modalidade
 * com infraestrutura desligada no servidor não pode ser atribuída.
 */
final class AttendantModalityManager
{
    public function __construct(private readonly ConsultationModalityCatalog $catalog) {}

    /**
     * Sincronização estrita: cada modalidade marcada precisa de preço.
     *
     * @param  array<int, string>  $modalities
     * @param  array<string, int|string|null>  $prices
     */
    public function updateOfferedModalities(
        AttendantProfile $profile,
        User $actor,
        array $modalities,
        array $prices,
    ): AttendantProfile {
        $requested = collect($modalities)
            ->map(static fn ($value): ConsultationModality => ConsultationModality::from((string) $value))
            ->push(ConsultationModality::Text)
            ->unique()
            ->values();

        $normalized = [];

        foreach ($requested as $modality) {
            if (! $this->catalog->isEnabled($modality)) {
                throw ValidationException::withMessages([
                    'offered_modalities' => 'A modalidade '.$modality->label().' está desativada na infraestrutura.',
                ]);
            }

            $price = $prices[$modality->value] ?? null;

            if ($price === null || $price === '') {
                throw ValidationException::withMessages([
                    'prices' => 'Informe o preço por minuto de '.$modality->label().' para este atendente.',
                ]);
            }

            $normalized[$modality->value] = (int) $price;
        }

        return $this->persist($profile, $actor, $requested->all(), $normalized);
    }

    /**
     * Sincronização parcial usada na edição do usuário.
     *
     * Preço em branco remove a modalidade do profissional. Modalidades com
     * infraestrutura desligada não são tocadas, para que editar outros campos
     * não apague configuração existente.
     *
     * @param  array<string, int|null>  $prices
     */
    public function syncPrices(AttendantProfile $profile, User $actor, array $prices): AttendantProfile
    {
        $offered = [ConsultationModality::Text];
        $normalized = [];
        $removable = [];

        foreach (ConsultationModality::cases() as $modality) {
            if (! $this->catalog->isEnabled($modality)) {
                continue;
            }

            $removable[] = $modality->value;

            $price = $prices[$modality->value] ?? null;

            if ($price === null) {
                continue;
            }

            $offered[] = $modality;
            $normalized[$modality->value] = (int) $price;
        }

        return $this->persist($profile, $actor, $offered, $normalized, $removable);
    }

    /**
     * @param  array<int, ConsultationModality>  $modalities
     * @param  array<string, int>  $prices
     * @param  array<int, string>  $removable  Modalidades cujo preço pode ser apagado quando não informado.
     */
    private function persist(
        AttendantProfile $profile,
        User $actor,
        array $modalities,
        array $prices,
        array $removable = [],
    ): AttendantProfile {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem alterar modalidades de atendentes.');
        }

        $order = array_flip(ConsultationModality::values());

        $values = collect($modalities)
            ->unique(static fn (ConsultationModality $modality): string => $modality->value)
            ->sortBy(static fn (ConsultationModality $modality): int => $order[$modality->value])
            ->map(static fn (ConsultationModality $modality): string => $modality->value)
            ->values()
            ->all();

        return DB::transaction(function () use ($profile, $actor, $values, $prices, $removable): AttendantProfile {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $before = $locked->offeredModalityValues();
            $beforePrices = $locked->pricesByModality();

            $locked->offered_modalities = $values;
            $locked->save();

            foreach ($prices as $modality => $cents) {
                AttendantModalityPrice::query()->updateOrCreate(
                    [
                        'attendant_profile_id' => $locked->getKey(),
                        'modality' => $modality,
                    ],
                    ['price_per_minute_cents' => $cents],
                );
            }

            if ($removable !== []) {
                AttendantModalityPrice::query()
                    ->where('attendant_profile_id', $locked->getKey())
                    ->whereIn('modality', $removable)
                    ->whereNotIn('modality', array_keys($prices))
                    ->delete();
            }

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'attendant.modalities_updated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => $values,
                    'prices_before' => $beforePrices,
                    'prices_after' => $prices,
                ],
            ]);

            $locked->unsetRelation('modalityPrices');

            return $locked;
        });
    }
}
