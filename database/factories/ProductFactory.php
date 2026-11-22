<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credits = fake()->randomElement([5, 10, 20, 30, 50]);

        return [
            'title' => 'Pacote '.$credits.' créditos',
            'description' => fake()->sentence(12),
            'credits_amount' => $credits,
            'price_cents' => $credits * 100,
            'image_path' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
