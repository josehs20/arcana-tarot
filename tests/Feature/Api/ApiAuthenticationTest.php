<?php

namespace Tests\Feature\Api;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Login, cadastro, logout e reenvio de confirmação pela API móvel.
 *
 * A API não cria sessão: cada login devolve um Bearer token do Sanctum com a
 * ability do papel. O logout revoga apenas o token usado na requisição.
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Cliente API',
            'email' => 'cliente.api@gmail.com',
            'phone' => '(11) 97777-6666',
            'birth_date' => '1990-05-20',
            'gender' => Gender::Female->value,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'device_name' => 'Pixel 8',
        ];
    }

    public function test_client_can_log_in_and_use_the_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Cliente Login',
            'email' => 'cliente.login@gmail.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '  CLIENTE.LOGIN@GMAIL.COM ',
            'password' => 'password',
            'device_name' => 'Pixel 8',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['client'])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'cliente.login@gmail.com')
            ->assertJsonPath('data.user.role', UserRole::Client->value)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'abilities',
                    'expires_at',
                    'user' => ['id', 'name', 'email', 'role', 'is_active'],
                ],
            ]);

        $this->assertIsString($response->json('data.token'));
        $this->assertNotNull($response->json('data.expires_at'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 8',
        ]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_rejects_wrong_password_without_creating_a_token(): void
    {
        User::factory()->create(['email' => 'cliente.login@gmail.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente.login@gmail.com',
            'password' => 'senha-errada',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_inactive_accounts_without_revealing_the_reason(): void
    {
        User::factory()->create([
            'email' => 'cliente.inativo@gmail.com',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente.inativo@gmail.com',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_obtain_a_mobile_token(): void
    {
        User::factory()->admin()->create(['email' => 'admin@arcana.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@arcana.test',
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'O aplicativo móvel é exclusivo para clientes e atendentes.',
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_attendant_receives_the_attendant_ability(): void
    {
        $attendant = User::factory()->attendant()->create(['email' => 'atendente@arcana.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'atendente@arcana.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.abilities', ['attendant'])
            ->assertJsonPath('data.user.role', UserRole::Attendant->value);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $attendant->id,
            'name' => 'mobile',
        ]);
    }

    public function test_registration_creates_the_client_wallet_and_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['client'])
            ->assertJsonPath('data.user.email', 'cliente.api@gmail.com')
            ->assertJsonPath('data.user.phone', '11977776666')
            ->assertJsonPath('data.user.email_verified_at', null);

        $user = User::query()->where('email', 'cliente.api@gmail.com')->sole();

        $this->assertSame(UserRole::Client, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->wallet);
        $this->assertSame(0, $user->wallet->balance_cents);

        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_registration_uses_the_same_rules_as_the_web_form(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload([
            'email' => 'cliente@yahoo.com',
            'phone' => null,
            'birth_date' => now()->subYears(17)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'birth_date']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('primeiro', ['client']);
        $second = $user->createToken('segundo', ['client']);

        $this->withToken($first->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // O guard do Sanctum é um singleton e guarda o usuário resolvido; no
        // teste, cada requisição precisa resolver o token de novo.
        $this->app['auth']->forgetGuards();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $first->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $second->accessToken->id,
        ]);

        $this->withToken($first->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withToken($second->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_logout_requires_a_token(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_unverified_user_can_request_a_new_confirmation_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['email' => 'pendente@gmail.com']);
        $token = $user->createToken('mobile', ['client'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Enviamos um novo link de confirmação para pendente@gmail.com.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verified_user_does_not_receive_another_confirmation_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['client'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Seu e-mail já está confirmado.');

        Notification::assertNothingSent();
    }

    public function test_auth_endpoints_are_rate_limited_by_ip(): void
    {
        config(['api.auth_rate_limit_per_minute' => 2]);

        User::factory()->create(['email' => 'cliente.limite@gmail.com']);

        $payload = [
            'email' => 'cliente.limite@gmail.com',
            'password' => 'senha-errada',
        ];

        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $payload)->assertTooManyRequests();
    }
}
