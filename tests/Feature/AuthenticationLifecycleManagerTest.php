<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\AuthenticationLifecycleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationLifecycleManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_login_brings_an_offline_attendant_online(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create();

        app(AuthenticationLifecycleManager::class)->afterLogin($attendant);

        $profile->refresh();

        $this->assertSame(AttendantAvailability::Online, $profile->availability);
        $this->assertNotNull($profile->last_seen_at);
    }

    public function test_after_login_is_idempotent_for_an_already_online_attendant(): void
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        app(AuthenticationLifecycleManager::class)->afterLogin($attendant);

        $this->assertSame(0, AuditLog::query()
            ->where('action', 'attendant.availability_changed')
            ->count());
    }

    public function test_after_logout_ends_the_active_consultation_and_takes_the_attendant_offline(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        $consultation = Consultation::factory()->active()->create([
            'attendant_id' => $attendant->id,
        ]);

        app(AuthenticationLifecycleManager::class)->afterLogout($attendant);

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::AttendantLoggedOut, $consultation->ended_reason);
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
    }
}
