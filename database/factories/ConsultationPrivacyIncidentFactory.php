<?php

namespace Database\Factories;

use App\Enums\PrivacyIncidentStatus;
use App\Enums\SensitiveDataCategory;
use App\Models\Consultation;
use App\Models\ConsultationPrivacyIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationPrivacyIncident>
 */
class ConsultationPrivacyIncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'consultation_id' => Consultation::factory()->active(),
            'user_id' => fn (array $attributes) => Consultation::query()
                ->findOrFail($attributes['consultation_id'])
                ->client_id,
            'category' => SensitiveDataCategory::Phone->value,
            'status' => PrivacyIncidentStatus::Blocked->value,
            'detected_types' => [[
                'category' => SensitiveDataCategory::Phone->value,
                'label' => SensitiveDataCategory::Phone->label(),
                'masked' => '(11) *****-4321',
            ]],
            'masked_excerpt' => 'Meu telefone é (11) *****-4321, me chama lá.',
            'body_original' => 'Meu telefone é (11) 98765-4321, me chama lá.',
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function reviewed(?User $reviewer = null): static
    {
        return $this->state(fn (): array => [
            'status' => PrivacyIncidentStatus::Reviewed->value,
            'reviewed_by' => $reviewer?->getKey() ?? User::factory()->admin(),
            'reviewed_at' => now(),
            'review_notes' => 'Analisado pela administração.',
        ]);
    }

    public function category(SensitiveDataCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category->value,
            'detected_types' => [[
                'category' => $category->value,
                'label' => $category->label(),
                'masked' => '***',
            ]],
        ]);
    }
}
