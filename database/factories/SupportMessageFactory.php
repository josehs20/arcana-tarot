<?php

namespace Database\Factories;

use App\Enums\SupportSenderType;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => SupportConversation::factory(),
            'sender_type' => SupportSenderType::Visitor,
            'sender_id' => null,
            'body' => fake()->sentence(),
            'read_at' => null,
        ];
    }

    public function fromOperator(?User $admin = null): static
    {
        return $this->state(fn (): array => [
            'sender_type' => SupportSenderType::Operator,
            'sender_id' => $admin?->getKey() ?? User::factory()->admin(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => [
            'read_at' => now(),
        ]);
    }
}
