<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_as_client(): void
    {
        $response = $this->post('/register', [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '(11) 99999-9999',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ]);

        $response->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'cliente@example.com',
            'phone' => '11999999999',
            'phone_verified_at' => null,
            'role' => UserRole::Client->value,
        ]);

        $user = User::query()->where('email', 'cliente@example.com')->firstOrFail();
        $this->assertNotNull($user->wallet);
        $this->assertSame(0, $user->wallet->balance_cents);
    }

    public function test_registration_normalizes_phone_and_email(): void
    {
        $this->post('/register', [
            'name' => '  Maria   da Silva  ',
            'email' => '  MARIA@Example.COM ',
            'phone' => '+55 (11) 98888-7777',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('users', [
            'name' => 'Maria da Silva',
            'email' => 'maria@example.com',
            'phone' => '11988887777',
        ]);
    }

    public function test_registration_requires_a_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente@example.com']);
    }

    public function test_registration_rejects_invalid_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '1234',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_registration_ignores_role_input(): void
    {
        $response = $this->post('/register', [
            'name' => 'Hacker',
            'email' => 'hacker@example.com',
            'phone' => '(11) 97777-6666',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'role' => UserRole::Admin->value,
        ]);

        $response->assertRedirect(route('client.dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'hacker@example.com',
            'role' => UserRole::Client->value,
        ]);
    }

    public function test_user_can_login_and_is_redirected_by_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_attendant_logout_sets_availability_to_offline(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        $this->actingAs($attendant)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);

        $audit = AuditLog::query()->where('action', 'attendant.availability_changed')->sole();

        $this->assertSame($attendant->id, $audit->actor_id);
        $this->assertSame($profile->getMorphClass(), $audit->subject_type);
        $this->assertSame($profile->id, $audit->subject_id);
        $this->assertSame('logout', $audit->metadata['reason']);
    }

    public function test_attendant_logout_ends_active_consultation_and_sets_availability_offline(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        $this->actingAs($attendant)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);

        $audit = AuditLog::query()->where('action', 'attendant.availability_changed')->sole();

        $this->assertSame($attendant->id, $audit->actor_id);
        $this->assertSame('logout', $audit->metadata['reason']);
    }

    public function test_forgot_password_sends_reset_link(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');
    }
}
