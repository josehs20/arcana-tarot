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
            'modality' => 'text',
            'commission_rate_bps' => 3000,
            'credit_unit_price_cents' => 100,
            'price_per_minute_cents' => 200,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsultationStatus::Active->value,
            'started_at' => now(),
        ]);
    }

    public function audio(): static
    {
        return $this->state(fn (array $attributes) => [
            'modality' => 'audio',
        ]);
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'modality' => 'video',
        ]);
    }
}
