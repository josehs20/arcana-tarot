<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendantApplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '  Ana   Silva ',
            'email' => ' Ana.Silva@Example.COM ',
            'phone' => '(11) 99999-8888',
            'city' => ' São Paulo ',
            'state' => 'sp',
            'experience_summary' => 'Atuo com Tarot há mais de cinco anos, com formação e prática contínua.',
            'message' => 'Conduzo atendimentos acolhedores, com escuta cuidadosa e explicações claras.',
            'specialties' => 'Amor, carreira, amor, Autoconhecimento',
            'portfolio_url' => 'https://example.com/ana',
            'consent' => '1',
        ], $overrides);
    }

    public function test_public_form_renders_with_consent_privacy_link_and_honeypot(): void
    {
        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('Formulário de candidatura')
            ->assertSee('name="consent"', false)
            ->assertSee('name="website"', false)
            ->assertSee(route('legal.privacy'), false)
            ->assertSee('não garante aprovação')
            ->assertDontSee('Página em preparação')
            ->assertDontSee('name="cpf"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_valid_submission_creates_pending_application_with_normalized_data(): void
    {
        $this->post(route('work-with-us.store'), $this->validPayload())
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHas('application_submitted', true);

        $this->assertDatabaseCount('attendant_applications', 1);

        $application = AttendantApplication::query()->firstOrFail();

        $this->assertSame('Ana Silva', $application->name);
        $this->assertSame('ana.silva@example.com', $application->email);
        $this->assertSame('11999998888', $application->phone);
        $this->assertSame('São Paulo', $application->city);
        $this->assertSame('SP', $application->state);
        $this->assertSame(['Amor', 'Carreira', 'Autoconhecimento'], $application->specialties);
        $this->assertSame(ApplicationStatus::Pending, $application->status);
        $this->assertNull($application->reviewed_by);
        $this->assertNull($application->review_notes);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotNull($application->consent_at);
        $this->assertNotNull($application->status_changed_at);
        $this->assertSame(0, User::query()->count());
    }

    public function test_validation_rejects_empty_submission_and_creates_nothing(): void
    {
        $this->from(route('work-with-us.index'))
            ->post(route('work-with-us.store'), [])
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHasErrors([
                'name',
                'email',
                'phone',
                'experience_summary',
                'message',
                'consent',
            ]);

        $this->assertDatabaseCount('attendant_applications', 0);
    }

    public function test_optional_location_specialties_and_portfolio_can_be_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['city'], $payload['state'], $payload['specialties'], $payload['portfolio_url']);

        $this->post(route('work-with-us.store'), $payload)
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHasNoErrors();

        $application = AttendantApplication::query()->firstOrFail();

        $this->assertNull($application->city);
        $this->assertNull($application->state);
        $this->assertNull($application->specialties);
        $this->assertNull($application->portfolio_url);
    }

    public function test_extra_fields_cannot_manipulate_status_reviewer_or_notes(): void
    {
        $this->post(route('work-with-us.store'), $this->validPayload([
            'status' => ApplicationStatus::Approved->value,
            'reviewed_by' => 1,
            'review_notes' => 'nota injetada',
            'reviewed_at' => now()->toDateTimeString(),
            'submitted_at' => now()->subYear()->toDateTimeString(),
        ]))->assertRedirect(route('work-with-us.index'));

        $application = AttendantApplication::query()->firstOrFail();

        $this->assertSame(ApplicationStatus::Pending, $application->status);
        $this->assertNull($application->reviewed_by);
        $this->assertNull($application->review_notes);
        $this->assertNull($application->reviewed_at);
        $this->assertTrue($application->submitted_at->isToday());
    }

    public function test_honeypot_submission_returns_neutral_success_without_persisting(): void
    {
        $this->post(route('work-with-us.store'), $this->validPayload([
            'website' => 'https://spam.example',
        ]))
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHas('application_submitted', true);

        $this->assertDatabaseCount('attendant_applications', 0);
    }

    public function test_public_submission_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('work-with-us.store'), $this->validPayload([
                'email' => "rate{$attempt}@example.com",
                'phone' => '1199999'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT),
            ]))->assertRedirect(route('work-with-us.index'));
        }

        $this->post(route('work-with-us.store'), $this->validPayload([
            'email' => 'rate6@example.com',
            'phone' => '11999990006',
        ]))->assertStatus(429);

        $this->assertDatabaseCount('attendant_applications', 5);
    }

    public function test_pending_or_under_review_application_blocks_new_submission_with_feedback(): void
    {
        foreach ([ApplicationStatus::Pending, ApplicationStatus::UnderReview] as $status) {
            AttendantApplication::factory()->create([
                'email' => "bloqueado-{$status->value}@example.com",
                'status' => $status->value,
            ]);

            $this->from(route('work-with-us.index'))
                ->post(route('work-with-us.store'), $this->validPayload([
                    'email' => "BLOQUEADO-{$status->value}@example.com",
                ]))
                ->assertRedirect(route('work-with-us.index'))
                ->assertSessionHasErrors('email');
        }

        $this->assertDatabaseCount('attendant_applications', 2);
    }

    public function test_approved_application_blocks_new_submission(): void
    {
        AttendantApplication::factory()->create([
            'email' => 'aprovado@example.com',
            'status' => ApplicationStatus::Approved->value,
        ]);

        $this->from(route('work-with-us.index'))
            ->post(route('work-with-us.store'), $this->validPayload([
                'email' => 'aprovado@example.com',
            ]))
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('attendant_applications', 1);
    }

    public function test_reapplication_after_rejection_respects_documented_cooldown(): void
    {
        AttendantApplication::factory()->create([
            'email' => 'recente@example.com',
            'status' => ApplicationStatus::Rejected->value,
            'reviewed_at' => now()->subDays(29),
        ]);

        $this->from(route('work-with-us.index'))
            ->post(route('work-with-us.store'), $this->validPayload([
                'email' => 'recente@example.com',
            ]))
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('attendant_applications', 1);

        AttendantApplication::factory()->create([
            'email' => 'antiga@example.com',
            'status' => ApplicationStatus::Rejected->value,
            'reviewed_at' => now()->subDays(31),
        ]);

        $this->post(route('work-with-us.store'), $this->validPayload([
            'email' => 'antiga@example.com',
        ]))
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('attendant_applications', 3);
        $this->assertSame(2, AttendantApplication::query()->where('email', 'antiga@example.com')->count());
    }

    public function test_confirmation_is_neutral_and_does_not_expose_internal_data(): void
    {
        $this->post(route('work-with-us.store'), $this->validPayload())
            ->assertRedirect(route('work-with-us.index'));

        $application = AttendantApplication::query()->firstOrFail();

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('Recebemos sua candidatura')
            ->assertDontSee($application->email)
            ->assertDontSee('review_notes')
            ->assertDontSee('Ainda não assumida')
            ->assertDontSee('Candidatura #');
    }
}
