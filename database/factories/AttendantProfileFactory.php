<?php

namespace Database\Factories;

use App\Enums\AttendantAvailability;
use App\Enums\AttendantRequestSound;
use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendantProfile>
 */
class AttendantProfileFactory extends Factory
{
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
}
