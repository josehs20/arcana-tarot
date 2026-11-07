<?php

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaign>
 */
class EmailCampaignFactory extends Factory
{
    protected $model = EmailCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->admin(),
            'subject' => 'Novidades do Arcana Tarot',
            'body' => "Olá, {{nome}}!\n\nTemos novidades no Arcana Tarot.",
            'recipients_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'filters' => null,
        ];
    }
}
