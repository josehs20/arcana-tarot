<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendantOnboardingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_published_attendant_with_explicit_rate(): void
    {
        $this->artisan('arcana:create-attendant', [
            '--name' => 'Atendente Operacional',
            '--email' => 'atendente.operacional@example.com',
            '--password' => 'senha-segura-123',
            '--display-name' => 'Atendente Operacional',
            '--commission-rate' => '25',
            '--publish' => true,
        ])->assertExitCode(0);

        $user = User::query()
            ->where('email', 'atendente.operacional@example.com')
            ->sole();

        $this->assertSame(UserRole::Attendant, $user->role);
        $this->assertTrue($user->is_active);

        $profile = $user->attendantProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_published);
        $this->assertSame(AttendantAvailability::Offline, $profile->availability);
        $this->assertSame(2500, $profile->commission_rate_bps);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendant.created_by_operator',
            'subject_id' => $profile->id,
        ]);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'attendant.created_by_operator')
            ->count());
    }

    public function test_command_rejects_duplicate_email_and_invalid_rate(): void
    {
        User::factory()->create(['email' => 'existente@example.com']);

        $this->artisan('arcana:create-attendant', [
            '--name' => 'Duplicado',
            '--email' => 'existente@example.com',
            '--password' => 'senha-segura-123',
            '--commission-rate' => '10',
        ])->assertExitCode(1);

        $this->artisan('arcana:create-attendant', [
            '--name' => 'Taxa Inválida',
            '--email' => 'taxa.invalida@example.com',
            '--password' => 'senha-segura-123',
            '--commission-rate' => '100.01',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'taxa.invalida@example.com']);
        $this->assertSame(0, AttendantProfile::query()->count());
    }
}
