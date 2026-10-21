<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationMessage>
 */
class ConsultationMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'consultation_id' => Consultation::factory(),
            'sender_id' => User::factory(),
            'type' => MessageType::Text->value,
            'body' => fake()->sentence(),
        ];
    }

    public function tarotCard(): static
    {
        return $this->state(function (array $attributes) {
            $card = TarotCard::factory()->create();

            return [
                'type' => MessageType::TarotCard->value,
                'body' => null,
                'tarot_card_id' => $card->id,
                'deck_name' => $card->deck->name,
                'card_name' => $card->name,
                'image_path' => $card->image_path,
            ];
        });
    }
}
