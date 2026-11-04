<?php

namespace Tests\Feature;

use App\Enums\AttendantRequestSound;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preferência de som de chamada por atendente.
 *
 * O toque pertence ao perfil profissional, é editado pela administração e
 * chega ao navegador do atendente pelo bloco `data-user-channel`. Nenhuma
 * regra de aceite ou cobrança depende do som escolhido.
 */
class AdminAttendantSoundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: AttendantProfile}
     */
    private function attendant(): array
    {
        $user = User::factory()->attendant()->create(['name' => 'Atendente do Som']);
        $profile = AttendantProfile::factory()->for($user)->create([
            'display_name' => 'Atendente do Som',
        ]);

        return [$user, $profile];
    }

    public function test_admin_sees_attendants_with_siren_as_the_default_sound(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($admin)
            ->get(route('admin.attendants.index'))
            ->assertOk()
            ->assertSee('Atendentes')
            ->assertSee('Som de chamada')
            ->assertSee($attendant->name)
            ->assertSee('Sirene (padrão)')
            ->assertSee('data-preview-request-sound', false);

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
    }

    public function test_admin_can_change_the_sound_and_the_action_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->patch(route('admin.attendants.sound.update', $attendant), [
                'request_sound' => AttendantRequestSound::Chime->value,
            ])
            ->assertRedirect(route('admin.attendants.index'))
            ->assertSessionHas('success');

        $this->assertSame(AttendantRequestSound::Chime, $profile->fresh()->requestSound());

        $audit = AuditLog::query()
            ->where('action', 'attendant.request_sound_updated')
            ->where('subject_id', $profile->id)
            ->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('siren', $audit->metadata['before']);
        $this->assertSame('chime', $audit->metadata['after']);
    }

    public function test_invalid_sound_is_rejected_without_changing_the_profile(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->patch(route('admin.attendants.sound.update', $attendant), [
                'request_sound' => 'airhorn',
            ])
            ->assertRedirect(route('admin.attendants.index'))
            ->assertSessionHasErrors('request_sound');

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'attendant.request_sound_updated')
            ->count());
    }

    public function test_only_admins_can_change_the_attendant_sound(): void
    {
        $client = User::factory()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($client)
            ->patch(route('admin.attendants.sound.update', $attendant), [
                'request_sound' => AttendantRequestSound::Pulse->value,
            ])
            ->assertForbidden();

        $this->actingAs($attendant)
            ->patch(route('admin.attendants.sound.update', $attendant), [
                'request_sound' => AttendantRequestSound::Pulse->value,
            ])
            ->assertForbidden();

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
    }

    public function test_attendant_channel_carries_the_configured_sound_and_client_does_not(): void
    {
        [$attendant, $profile] = $this->attendant();
        $profile->forceFill(['request_sound' => AttendantRequestSound::Bell->value])->save();

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('data-request-sound="bell"', false);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('data-request-sound', false);
    }
}
