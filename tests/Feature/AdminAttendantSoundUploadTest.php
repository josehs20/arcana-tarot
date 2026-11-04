<?php

namespace Tests\Feature;

use App\Enums\AttendantRequestSound;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Upload do MP3 personalizado de aviso de nova solicitação.
 *
 * O arquivo é validado pelo conteúdo (não pela extensão) e a duração é lida
 * pelo servidor, sem depender de JavaScript ou de ferramentas externas.
 */
class AdminAttendantSoundUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: AttendantProfile}
     */
    private function attendant(): array
    {
        $user = User::factory()->attendant()->create(['name' => 'Atendente do MP3']);
        $profile = AttendantProfile::factory()->for($user)->create([
            'display_name' => 'Atendente do MP3',
        ]);

        return [$user, $profile];
    }

    /**
     * Gera quadros MPEG-1 Layer III válidos de 128 kbps a 44,1 kHz.
     * Cada quadro dura 1152/44100 segundos (aprox. 0,0261 s).
     */
    private function mp3(int $frames): string
    {
        $frame = "\xFF\xFB\x90\x00".str_repeat("\0", 413);

        return str_repeat($frame, $frames);
    }

    private function upload(User $admin, User $attendant, int $frames = 100): TestResponse
    {
        return $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->post(route('admin.attendants.sound.upload', $attendant), [
                'request_sound_file' => UploadedFile::fake()->createWithContent(
                    'aviso.mp3',
                    $this->mp3($frames),
                ),
            ]);
    }

    public function test_admin_can_upload_an_mp3_of_up_to_five_seconds(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->upload($admin, $attendant)
            ->assertRedirect(route('admin.attendants.index'))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame(AttendantRequestSound::Custom, $profile->requestSound());
        $this->assertNotNull($profile->request_sound_path);
        Storage::disk('public')->assertExists($profile->request_sound_path);

        $audit = AuditLog::query()
            ->where('action', 'attendant.request_sound_file_uploaded')
            ->where('subject_id', $profile->id)
            ->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('siren', $audit->metadata['before']);
        $this->assertSame('custom', $audit->metadata['after']);
        $this->assertSame(2.612, $audit->metadata['duration_seconds']);
        $this->assertSame($profile->request_sound_path, $audit->metadata['file']);

        $this->actingAs($admin)
            ->get(route('admin.attendants.index'))
            ->assertOk()
            ->assertSee('Personalizado (MP3)')
            ->assertSee('Remover MP3')
            ->assertSee('data-preview-request-sound-url=', false);
    }

    public function test_a_new_upload_replaces_and_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->upload($admin, $attendant)->assertSessionHasNoErrors();
        $firstPath = $profile->fresh()->request_sound_path;

        $this->upload($admin, $attendant, 120)->assertSessionHasNoErrors();
        $secondPath = $profile->fresh()->request_sound_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_an_mp3_longer_than_five_seconds_is_rejected(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->upload($admin, $attendant, 200)
            ->assertRedirect(route('admin.attendants.index'))
            ->assertSessionHasErrors('request_sound_file');

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
        $this->assertNull($profile->fresh()->request_sound_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'attendant.request_sound_file_uploaded')
            ->count());
    }

    public function test_a_file_that_is_not_mp3_is_rejected(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->post(route('admin.attendants.sound.upload', $attendant), [
                'request_sound_file' => UploadedFile::fake()->createWithContent('aviso.mp3', 'não é áudio'),
            ])
            ->assertSessionHasErrors('request_sound_file');

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_can_remove_the_custom_mp3_and_falls_back_to_siren(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();
        $path = 'attendant-sounds/aviso.mp3';

        Storage::disk('public')->put($path, $this->mp3(100));
        $profile->forceFill([
            'request_sound' => AttendantRequestSound::Custom->value,
            'request_sound_path' => $path,
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->delete(route('admin.attendants.sound.destroy', $attendant))
            ->assertRedirect(route('admin.attendants.index'))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame(AttendantRequestSound::Siren, $profile->requestSound());
        $this->assertNull($profile->request_sound_path);
        Storage::disk('public')->assertMissing($path);

        $audit = AuditLog::query()
            ->where('action', 'attendant.request_sound_file_removed')
            ->where('subject_id', $profile->id)
            ->sole();

        $this->assertSame('custom', $audit->metadata['before']);
        $this->assertSame('siren', $audit->metadata['after']);
        $this->assertSame($path, $audit->metadata['file']);
    }

    public function test_custom_sound_cannot_be_selected_without_an_existing_mp3(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant();

        $this->actingAs($admin)
            ->from(route('admin.attendants.index'))
            ->patch(route('admin.attendants.sound.update', $attendant), [
                'request_sound' => AttendantRequestSound::Custom->value,
            ])
            ->assertSessionHasErrors('request_sound');

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
    }

    public function test_only_admins_can_upload_the_custom_mp3(): void
    {
        Storage::fake('public');

        [$attendant, $profile] = $this->attendant();

        $this->actingAs($attendant)
            ->post(route('admin.attendants.sound.upload', $attendant), [
                'request_sound_file' => UploadedFile::fake()->createWithContent(
                    'aviso.mp3',
                    $this->mp3(100),
                ),
            ])
            ->assertForbidden();

        $this->assertSame(AttendantRequestSound::Siren, $profile->fresh()->requestSound());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_attendant_channel_carries_the_custom_mp3_url(): void
    {
        Storage::fake('public');

        [$attendant, $profile] = $this->attendant();
        $path = 'attendant-sounds/aviso.mp3';

        Storage::disk('public')->put($path, $this->mp3(100));
        $profile->forceFill([
            'request_sound' => AttendantRequestSound::Custom->value,
            'request_sound_path' => $path,
        ])->save();

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('data-request-sound="custom"', false)
            ->assertSee('data-request-sound-url=', false)
            ->assertSee($path, false);
    }
}
