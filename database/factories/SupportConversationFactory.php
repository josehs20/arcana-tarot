<?php

namespace Database\Factories;

use App\Enums\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportConversation>
 */
class SupportConversationFactory extends Factory
{
    protected $model = SupportConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'visitor_name' => fake()->name(),
            'visitor_email' => fake()->safeEmail(),
            'visitor_token_hash' => hash('sha256', Str::random(48)),
            'status' => SupportConversationStatus::Waiting,
            'assigned_user_id' => null,
            'last_message_at' => now(),
            'closed_at' => null,
        ];
    }

    public function inProgress(?User $admin = null): static
    {
        return $this->state(fn (): array => [
            'status' => SupportConversationStatus::InProgress,
            'assigned_user_id' => $admin?->getKey() ?? User::factory()->admin(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => SupportConversationStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
