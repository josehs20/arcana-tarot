<?php

namespace Database\Factories;

use App\Models\TarotCard;
use App\Models\TarotDeck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TarotCard>
 */
class TarotCardFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'deck_id' => TarotDeck::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 0,
            'meaning_upright' => fake()->sentence(),
            'meaning_reversed' => fake()->sentence(),
            'image_path' => 'cards/'.Str::random(32).'.jpg',
            'is_active' => true,
        ];
    }
}
