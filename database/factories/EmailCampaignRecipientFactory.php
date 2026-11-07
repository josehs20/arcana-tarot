<?php

namespace Database\Factories;

use App\Enums\EmailRecipientStatus;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaignRecipient>
 */
class EmailCampaignRecipientFactory extends Factory
{
    protected $model = EmailCampaignRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_campaign_id' => EmailCampaign::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'status' => EmailRecipientStatus::Pending,
            'error' => null,
            'sent_at' => null,
        ];
    }

    /**
     * E-mail entregue ao transporte.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EmailRecipientStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ]);
    }

    /**
     * E-mail que esgotou as tentativas de envio.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EmailRecipientStatus::Failed,
            'error' => 'Falha ao enviar o e-mail.',
            'sent_at' => null,
        ]);
    }
}
