<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\Gender;
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
            'birth_date' => '1990-05-20',
            'gender' => Gender::Female->value,
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
            'gender' => Gender::Female->value,
        ]);

        $user = User::query()->where('email', 'cliente@example.com')->firstOrFail();
        $this->assertSame('1990-05-20', $user->birth_date?->format('Y-m-d'));
        $this->assertSame(Gender::Female, $user->gender);
        $this->assertSame('Feminino', $user->genderLabel());
        $this->assertNotNull($user->wallet);
        $this->assertSame(0, $user->wallet->balance_cents);
    }

    public function test_registration_requires_a_gender(): void
    {
        $payload = [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '(11) 99999-9999',
            'birth_date' => '1990-05-20',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ];

        $this->post('/register', $payload)->assertSessionHasErrors('gender');

        $this->post('/register', $payload + ['gender' => 'indefinido'])
            ->assertSessionHasErrors('gender');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente@example.com']);
    }

    public function test_registration_requires_a_birth_date(): void
    {
        $this->post('/register', [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '(11) 99999-9999',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertSessionHasErrors('birth_date');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente@example.com']);
    }

    /**
     * A data de nascimento é a identidade usada no cartão de atendimento, e a
     * idade mínima é uma exigência dos termos de uso.
     */
    public function test_registration_enforces_the_adult_age_range(): void
    {
        $payload = [
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '(11) 99999-9999',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ];

        $this->post('/register', $payload + [
            'birth_date' => now()->subYears(17)->toDateString(),
        ])->assertSessionHasErrors('birth_date');

        $this->post('/register', $payload + [
            'birth_date' => now()->subYears(130)->toDateString(),
        ])->assertSessionHasErrors('birth_date');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente@example.com']);
    }

    public function test_registration_normalizes_phone_and_email(): void
    {
        $this->post('/register', [
            'name' => '  Maria   da Silva  ',
            'email' => '  MARIA@Example.COM ',
            'phone' => '+55 (11) 98888-7777',
            'birth_date' => '1985-01-31',
            'gender' => Gender::Other->value,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('users', [
            'name' => 'Maria da Silva',
            'email' => 'maria@example.com',
            'phone' => '11988887777',
            'gender' => Gender::Other->value,
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
            'birth_date' => '1992-03-10',
            'gender' => Gender::Male->value,
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

    /**
     * O produto é pt-BR: as mensagens de erro do login precisam chegar
     * traduzidas ao usuário, inclusive as de validação dos campos.
     */
    public function test_login_error_messages_are_in_portuguese(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'email' => 'As credenciais informadas não correspondem aos nossos registros.',
        ]);

        $this->post('/login', [])->assertSessionHasErrors([
            'email' => 'O campo e-mail é obrigatório.',
            'password' => 'O campo senha é obrigatório.',
        ]);
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

    public function test_attendant_login_sets_availability_to_online(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create();

        $this->post('/login', [
            'email' => $attendant->email,
            'password' => 'password',
        ])->assertRedirect(route('attendant.dashboard'));

        $this->assertAuthenticatedAs($attendant);
        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
        $this->assertNotNull($profile->fresh()->last_seen_at);

        $audit = AuditLog::query()->where('action', 'attendant.availability_changed')->sole();

        $this->assertSame($attendant->id, $audit->actor_id);
        $this->assertSame('login', $audit->metadata['reason']);
        $this->assertSame('offline', $audit->metadata['from']);
        $this->assertSame('online', $audit->metadata['to']);
    }

    public function test_attendant_login_on_an_already_online_profile_does_not_duplicate_audit(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create();

        $this->post('/login', [
            'email' => $attendant->email,
            'password' => 'password',
        ])->assertRedirect(route('attendant.dashboard'));

        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_unpublished_attendant_login_keeps_availability_offline(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create([
            'is_published' => false,
        ]);

        $this->post('/login', [
            'email' => $attendant->email,
            'password' => 'password',
        ])->assertRedirect(route('attendant.dashboard'));

        $this->assertAuthenticatedAs($attendant);
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_attendant_login_does_not_change_availability_during_an_active_consultation(): void
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        $this->post('/login', [
            'email' => $attendant->email,
            'password' => 'password',
        ])->assertRedirect(route('attendant.dashboard'));

        $this->assertAuthenticatedAs($attendant);
        $this->assertSame(AttendantAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_client_login_does_not_touch_attendant_availability(): void
    {
        $client = User::factory()->create();
        AttendantProfile::factory()->offline()->create();

        $this->post('/login', [
            'email' => $client->email,
            'password' => 'password',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.availability_changed')->count());
    }

    public function test_forgot_password_sends_reset_link(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');
    }
}
