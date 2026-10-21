<?php

namespace Database\Factories;

use App\Enums\AttendantAvailability;
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
            'commission_rate_bps' => 3000,
            'is_published' => true,
            'availability' => AttendantAvailability::Offline->value,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AttendantAvailability::Online->value,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AttendantAvailability::Offline->value,
        ]);
    }
}
