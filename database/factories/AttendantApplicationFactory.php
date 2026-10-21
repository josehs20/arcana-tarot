<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendantApplication>
 */
class AttendantApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('119########'),
            'city' => fake()->city(),
            'state' => fake()->randomElement([
                'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT',
                'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO',
                'RR', 'SC', 'SP', 'SE', 'TO',
            ]),
            'experience_summary' => fake()->paragraph(),
            'message' => fake()->paragraph(),
            'specialties' => fake()->randomElements(
                ['Amor', 'Carreira', 'Autoconhecimento', 'Espiritualidade', 'Família'],
                3,
            ),
            'portfolio_url' => fake()->boolean(40) ? fake()->url() : null,
            'status' => ApplicationStatus::Pending->value,
            'submitted_at' => now(),
            'consent_at' => now(),
            'status_changed_at' => now(),
        ];
    }
}
