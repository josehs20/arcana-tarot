<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\AttendantApplication;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdminApplicationReviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function application(array $attributes = []): AttendantApplication
    {
        return AttendantApplication::factory()->create($attributes);
    }

    public function test_guest_is_redirected_to_login_for_every_admin_application_route(): void
    {
        $application = $this->application();

        $this->get(route('admin.applications.index'))->assertRedirect(route('login'));
        $this->get(route('admin.applications.show', $application))->assertRedirect(route('login'));
        $this->patch(route('admin.applications.start', $application))->assertRedirect(route('login'));
        $this->patch(route('admin.applications.approve', $application))->assertRedirect(route('login'));
        $this->patch(route('admin.applications.reject', $application))->assertRedirect(route('login'));
    }

    public function test_non_admin_and_inactive_admin_receive_forbidden(): void
    {
        $application = $this->application();

        $users = [
            User::factory()->create(),
            User::factory()->attendant()->create(),
            User::factory()->admin()->create(['is_active' => false]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('admin.applications.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.applications.show', $application))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.applications.start', $application))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.applications.approve', $application))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.applications.reject', $application))->assertForbidden();
        }
    }

    public function test_candidate_with_same_email_cannot_view_internal_notes(): void
    {
        $application = $this->application([
            'email' => 'candidata@example.com',
            'review_notes' => 'Nota interna confidencial',
        ]);

        $candidate = User::factory()->create(['email' => 'candidata@example.com']);

        $this->actingAs($candidate)
            ->get(route('admin.applications.show', $application))
            ->assertForbidden();

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertDontSee('Nota interna confidencial');
    }

    public function test_admin_can_list_and_filter_applications(): void
    {
        $admin = $this->admin();

        $alice = $this->application([
            'name' => 'Alice Pendente',
            'email' => 'alice@example.com',
            'status' => ApplicationStatus::Pending->value,
            'submitted_at' => now(),
        ]);

        $this->application([
            'name' => 'Bob Aprovado',
            'email' => 'bob@example.com',
            'status' => ApplicationStatus::Approved->value,
            'submitted_at' => now()->subDays(10),
        ]);

        $this->application([
            'name' => 'Carol Rejeitada',
            'email' => 'carol@example.com',
            'status' => ApplicationStatus::Rejected->value,
            'submitted_at' => now()->subDays(20),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.applications.index'))
            ->assertOk()
            ->assertSee('Alice Pendente')
            ->assertSee('Bob Aprovado')
            ->assertSee('Carol Rejeitada')
            ->assertSee(route('admin.applications.show', $alice), false)
            ->assertSee('aria-label="1 candidatura pendente"', false);

        $this->actingAs($admin)
            ->get(route('admin.applications.index', ['q' => 'Alice']))
            ->assertOk()
            ->assertSee('Alice Pendente')
            ->assertDontSee('Bob Aprovado')
            ->assertDontSee('Carol Rejeitada');

        $this->actingAs($admin)
            ->get(route('admin.applications.index', ['status' => ApplicationStatus::Approved->value]))
            ->assertOk()
            ->assertSee('Bob Aprovado')
            ->assertDontSee('Alice Pendente')
            ->assertDontSee('Carol Rejeitada');

        $this->actingAs($admin)
            ->get(route('admin.applications.index', [
                'date_from' => now()->subDays(5)->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertSee('Alice Pendente')
            ->assertDontSee('Bob Aprovado')
            ->assertDontSee('Carol Rejeitada');
    }

    public function test_admin_list_is_paginated(): void
    {
        $admin = $this->admin();

        AttendantApplication::factory()->count(16)->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.applications.index'))
            ->assertOk();

        $applications = $response->viewData('applications');

        $this->assertSame(15, $applications->perPage());
        $this->assertSame(16, $applications->total());
        $this->assertSame(2, $applications->lastPage());
    }

    public function test_admin_can_view_application_details_and_internal_notes(): void
    {
        $admin = $this->admin();
        $application = $this->application([
            'name' => 'Luna Detalhe',
            'email' => 'luna@example.com',
            'city' => 'Curitiba',
            'state' => 'PR',
            'specialties' => ['Amor', 'Carreira'],
            'portfolio_url' => 'https://example.com/luna',
            'review_notes' => 'Nota interna confidencial',
            'reviewed_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.applications.show', $application))
            ->assertOk()
            ->assertSee('Luna Detalhe')
            ->assertSee('luna@example.com')
            ->assertSee('Curitiba / PR')
            ->assertSee('Nota interna confidencial')
            ->assertSee('Assumir análise')
            ->assertSee('Aprovar candidatura')
            ->assertSee('Rejeitar candidatura')
            ->assertSee('não cria usuário, senha ou papel de atendente');
    }

    public function test_policy_only_allows_active_admins(): void
    {
        $application = $this->application();
        $admin = $this->admin();
        $client = User::factory()->create();
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', AttendantApplication::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $application));
        $this->assertTrue(Gate::forUser($admin)->allows('review', $application));
        $this->assertFalse(Gate::forUser($client)->allows('viewAny', AttendantApplication::class));
        $this->assertFalse(Gate::forUser($client)->allows('view', $application));
        $this->assertFalse(Gate::forUser($inactiveAdmin)->allows('review', $application));
    }

    public function test_admin_can_start_review_and_audit_records_actor_and_transition(): void
    {
        $admin = $this->admin();
        $application = $this->application();

        $this->actingAs($admin)
            ->patch(route('admin.applications.start', $application), [
                'internal_note' => 'Assumindo a análise',
            ])
            ->assertRedirect(route('admin.applications.show', $application))
            ->assertSessionHas('success');

        $application->refresh();

        $this->assertSame(ApplicationStatus::UnderReview, $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNotNull($application->review_started_at);
        $this->assertNotNull($application->status_changed_at);
        $this->assertSame('Assumindo a análise', $application->review_notes);

        $log = AuditLog::query()->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('attendant_application.under_review', $log->action);
        $this->assertSame($application->getMorphClass(), $log->subject_type);
        $this->assertSame($application->id, $log->subject_id);
        $this->assertSame(['from' => 'pending', 'to' => 'under_review'], $log->metadata);

        $this->actingAs($admin)
            ->get(route('admin.applications.show', $application))
            ->assertOk()
            ->assertSee('Análise assumida')
            ->assertSee($admin->name);
    }

    public function test_admin_approval_records_decision_without_creating_user_or_attendant_role(): void
    {
        $admin = $this->admin();
        $application = $this->application();
        $candidate = User::factory()->create(['email' => $application->email]);
        $usersBefore = User::query()->count();

        $this->actingAs($admin)
            ->patch(route('admin.applications.approve', $application), [
                'internal_note' => 'Documentação e experiência conferidas',
            ])
            ->assertRedirect(route('admin.applications.show', $application))
            ->assertSessionHas('success');

        $application->refresh();

        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertSame('Documentação e experiência conferidas', $application->review_notes);

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame(UserRole::Client, $candidate->fresh()->role);
        $this->assertSame(0, User::query()->where('role', UserRole::Attendant->value)->count());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant_application.approved',
            'subject_type' => $application->getMorphClass(),
            'subject_id' => $application->id,
        ]);
    }

    public function test_admin_can_reject_with_internal_note_and_decision_metadata(): void
    {
        $admin = $this->admin();
        $application = $this->application();

        $this->actingAs($admin)
            ->patch(route('admin.applications.reject', $application), [
                'internal_note' => 'Perfil não aderente ao posicionamento atual',
            ])
            ->assertRedirect(route('admin.applications.show', $application))
            ->assertSessionHas('success');

        $application->refresh();

        $this->assertSame(ApplicationStatus::Rejected, $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertSame('Perfil não aderente ao posicionamento atual', $application->review_notes);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant_application.rejected',
            'subject_id' => $application->id,
        ]);
    }

    public function test_decided_application_cannot_be_changed_again(): void
    {
        $admin = $this->admin();
        $application = $this->application([
            'status' => ApplicationStatus::Approved->value,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.applications.show', $application))
            ->patch(route('admin.applications.reject', $application), [
                'internal_note' => 'Tentativa de alteração',
            ])
            ->assertRedirect(route('admin.applications.show', $application))
            ->assertSessionHasErrors('status');

        $application->refresh();

        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_extra_fields_cannot_override_status_reviewer_or_notes(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $application = $this->application();

        $this->actingAs($admin)
            ->patch(route('admin.applications.approve', $application), [
                'internal_note' => 'Nota legítima',
                'status' => ApplicationStatus::Rejected->value,
                'reviewed_by' => $otherAdmin->id,
                'review_notes' => 'Nota injetada',
            ])
            ->assertRedirect(route('admin.applications.show', $application));

        $application->refresh();

        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertSame('Nota legítima', $application->review_notes);
    }
}
