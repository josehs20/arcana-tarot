<?php

namespace Database\Factories;

use App\Enums\AttendantAvailability;
use App\Enums\AttendantRequestSound;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendantProfile>
 */
class AttendantProfileFactory extends Factory
{
    /**
     * Preço por minuto padrão das modalidades oferecidas pelo perfil criado.
     *
     * O preço é sempre por atendente: consultas sem preço configurado são
     * recusadas pelo servidor.
     */
    public const DEFAULT_PRICE_PER_MINUTE_CENTS = 200;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->attendant(),
            'display_name' => fake()->name(),
            'bio' => fake()->sentence(),
            'photo_path' => null,
            'specialties' => ['Amor', 'Carreira', 'Saúde'],
            'offered_modalities' => ['text'],
            'request_sound' => AttendantRequestSound::Siren->value,
            'commission_rate_bps' => 3000,
            'is_published' => true,
            'availability' => AttendantAvailability::Offline->value,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AttendantAvailability::Online->value,
            'last_seen_at' => now(),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AttendantAvailability::Offline->value,
        ]);
    }

    /**
     * Atendente publicado que pausou a fila manualmente: recebe visitas no
     * diretório, mas não novas solicitações.
     */
    public function busy(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AttendantAvailability::Busy->value,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $modalities
     */
    public function offering(array $modalities): static
    {
        return $this->state(fn (array $attributes) => [
            'offered_modalities' => array_values($modalities),
        ]);
    }

    public function offeringAllModalities(): static
    {
        return $this->offering(['text', 'audio', 'video']);
    }

    public function withRequestSound(AttendantRequestSound|string $sound): static
    {
        $value = $sound instanceof AttendantRequestSound ? $sound->value : $sound;

        return $this->state(fn (array $attributes) => [
            'request_sound' => $value,
        ]);
    }

    /**
     * Cria uma linha de preço para cada modalidade oferecida pelo perfil.
     *
     * Sem preço a consulta não pode ser solicitada nem aceita, portanto o
     * padrão do factory mantém os cenários utilizáveis.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (AttendantProfile $profile): void {
            foreach ($profile->offeredModalities() as $modality) {
                $this->priceFor($profile, $modality->value)
                    ->updateOrCreate(
                        ['attendant_profile_id' => $profile->getKey(), 'modality' => $modality->value],
                        ['price_per_minute_cents' => self::DEFAULT_PRICE_PER_MINUTE_CENTS],
                    );
            }
        });
    }

    /**
     * Remove os preços criados pelo `configure()` para cobrir o bloqueio de
     * consultas sem preço configurado (nenhum fallback global é aplicado).
     */
    public function withoutModalityPrices(): static
    {
        return $this->afterCreating(function (AttendantProfile $profile): void {
            $profile->modalityPrices()->delete();
            $profile->unsetRelation('modalityPrices');
        });
    }

    /**
     * Define preços por modalidade. Use `null` para remover o preço de uma
     * modalidade e cobrir o bloqueio de consulta.
     *
     * @param  array<string, int|null>  $prices
     */
    public function withModalityPrices(array $prices): static
    {
        return $this->afterCreating(function (AttendantProfile $profile) use ($prices): void {
            foreach ($prices as $modality => $cents) {
                $query = $this->priceFor($profile, (string) $modality);

                if ($cents === null) {
                    $query->delete();

                    continue;
                }

                $query->updateOrCreate(
                    ['attendant_profile_id' => $profile->getKey(), 'modality' => $modality],
                    ['price_per_minute_cents' => (int) $cents],
                );
            }
        });
    }

    /**
     * @return Builder<AttendantModalityPrice>
     */
    private function priceFor(AttendantProfile $profile, string $modality): Builder
    {
        return AttendantModalityPrice::query()
            ->where('attendant_profile_id', $profile->getKey())
            ->where('modality', $modality);
    }
}
