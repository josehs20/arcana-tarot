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
            'phone' => fake()->phoneNumber(),
            'experience_summary' => fake()->sentence(),
            'message' => fake()->paragraph(),
            'status' => ApplicationStatus::Pending->value,
            'submitted_at' => now(),
        ];
    }
}
