<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => User::factory(),
            'attendant_id' => User::factory()->attendant(),
            'status' => ConsultationStatus::Requested->value,
            'commission_rate_bps' => 3000,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsultationStatus::Active->value,
            'started_at' => now(),
        ]);
    }
}
