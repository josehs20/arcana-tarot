<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\ConsultationTestimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationTestimonial>
 */
class ConsultationTestimonialFactory extends Factory
{
    protected $model = ConsultationTestimonial::class;

    public function definition(): array
    {
        return [
            'consultation_id' => Consultation::factory()->state([
                'status' => ConsultationStatus::Ended->value,
                'started_at' => now()->subMinutes(30),
                'ended_at' => now()->subMinutes(5),
            ]),
            'client_id' => fn (array $attributes) => Consultation::query()
                ->findOrFail($attributes['consultation_id'])
                ->client_id,
            'attendant_id' => fn (array $attributes) => Consultation::query()
                ->findOrFail($attributes['consultation_id'])
                ->attendant_id,
            'rating' => fake()->numberBetween(1, 5),
            'message' => fake()->sentences(3, true),
            'is_hidden' => false,
        ];
    }

    /**
     * Depoimento ocultado pela moderação.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hidden' => true,
            'hidden_at' => now(),
        ]);
    }
}
