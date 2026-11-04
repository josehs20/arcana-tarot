<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_shows_wallet_history_and_available_attendants(): void
    {
        $client = User::factory()->create(['name' => 'Cliente do Painel']);
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 12_500]);

        AttendantProfile::factory()->online()->create(['display_name' => 'Atendente Disponível']);
        AttendantProfile::factory()->offline()->create(['display_name' => 'Atendente Offline']);

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Cliente do Painel')
            ->assertSee('R$ 125,00')
            ->assertSee('Atendente Disponível')
            ->assertDontSee('Atendente Offline');
    }

    public function test_attendant_availability_can_be_toggled_and_is_audited(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->for($attendant)->create([
            'availability' => AttendantAvailability::Offline,
        ]);

        $this->actingAs($attendant)
            ->patch(route('attendant.availability.update'), ['availability' => 'online'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);

        $this->actingAs($attendant)
            ->patch(route('attendant.availability.update'), ['availability' => 'offline'])
            ->assertRedirect();

        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame(2, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_unpublished_attendant_cannot_go_online(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->for($attendant)->create([
            'availability' => AttendantAvailability::Offline,
            'is_published' => false,
        ]);

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.availability.update'), ['availability' => 'online'])
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('availability');

        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_availability_is_frozen_while_a_consultation_is_active(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('Ocupado')
            ->assertDontSee('Ficar online');

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.availability.update'), ['availability' => 'offline'])
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('availability');

        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
    }

    public function test_availability_rejects_values_other_than_online_or_offline(): void
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->for($attendant)->create();

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.availability.update'), ['availability' => 'busy'])
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('availability');
    }

    public function test_attendant_cannot_have_two_active_consultations(): void
    {
        $attendant = User::factory()->attendant()->create();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        try {
            Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);
            $this->fail('Uma segunda consulta ativa deveria ser rejeitada.');
        } catch (DomainException $exception) {
            $this->assertSame('O atendente já possui uma consulta ativa.', $exception->getMessage());
        }

        $this->assertSame(1, Consultation::query()
            ->where('attendant_id', $attendant->id)
            ->where('status', ConsultationStatus::Active->value)
            ->count());
    }

    public function test_admin_can_deactivate_a_client_and_the_action_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status', $client), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertFalse($client->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.deactivated',
            'subject_id' => $client->id,
        ]);
    }

    public function test_admin_cannot_deactivate_self_or_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $admin), ['is_active' => false])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $otherAdmin), ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($otherAdmin->fresh()->is_active);
    }

    public function test_deactivating_an_attendant_forces_them_offline(): void
    {
        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status', $attendant), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($attendant->fresh()->is_active);
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
    }

    public function test_admin_cannot_deactivate_an_attendant_with_an_active_consultation(): void
    {
        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status', $attendant), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($attendant->fresh()->is_active);
        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
    }

    public function test_client_cannot_access_admin_management_routes(): void
    {
        $client = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($client)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($client)->get(route('admin.consultations.index'))->assertForbidden();
        $this->actingAs($client)
            ->patch(route('admin.users.status', $target), ['is_active' => false])
            ->assertForbidden();
    }

    public function test_admin_user_index_filters_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'name' => 'Cliente Filtrado',
            'email' => 'cliente.filtrado@example.com',
        ]);
        $attendant = User::factory()->attendant()->create([
            'name' => 'Atendente Filtrado',
            'email' => 'atendente.filtrado@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => 'client']))
            ->assertOk()
            ->assertSee($client->email)
            ->assertDontSee($attendant->email);
    }

    public function test_admin_consultation_index_filters_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $activeClient = User::factory()->create(['name' => 'Cliente Com Consulta Ativa']);
        $endedClient = User::factory()->create(['name' => 'Cliente Com Consulta Encerrada']);

        $active = Consultation::factory()->active()->create(['client_id' => $activeClient->id]);
        Consultation::factory()->create([
            'client_id' => $endedClient->id,
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.consultations.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee($activeClient->name)
            ->assertDontSee($endedClient->name);

        $this->assertSame(ConsultationStatus::Active, $active->fresh()->status);
    }

    public function test_credit_purchase_page_has_no_manual_balance_edit(): void
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 5_000]);

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('R$ 50,00')
            ->assertSee('Nenhum saldo é creditado manualmente')
            ->assertDontSee('name="balance_cents"', false);

        $this->actingAs($client)
            ->post(route('client.credits.store'))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(5_000, $client->wallet->fresh()->balance_cents);
    }
}
