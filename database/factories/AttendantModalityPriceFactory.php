<?php

namespace Database\Factories;

use App\Enums\ConsultationModality;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendantModalityPrice>
 */
class AttendantModalityPriceFactory extends Factory
{
    protected $model = AttendantModalityPrice::class;

    public function definition(): array
    {
        return [
            'attendant_profile_id' => AttendantProfile::factory(),
            'modality' => ConsultationModality::Text->value,
            'price_per_minute_cents' => fake()->randomElement([100, 150, 200, 300]),
        ];
    }

    public function modality(ConsultationModality|string $modality): static
    {
        $value = $modality instanceof ConsultationModality ? $modality->value : $modality;

        return $this->state(fn (array $attributes) => [
            'modality' => $value,
        ]);
    }

    public function pricePerMinuteCents(int $cents): static
    {
        return $this->state(fn (array $attributes) => [
            'price_per_minute_cents' => $cents,
        ]);
    }
}
