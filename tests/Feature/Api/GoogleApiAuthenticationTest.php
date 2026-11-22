<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Login/cadastro com Google na API móvel.
 *
 * O app envia apenas um ID token. Os testes assinam tokens RS256 com uma
 * chave local e publicam o JWKS correspondente via Http::fake, exercitando o
 * mesmo caminho de validação usado contra o Google real.
 */
class GoogleApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const KID = 'test-key';

    private const JWKS_URI = 'https://www.googleapis.com/oauth2/v3/certs';

    private static ?\OpenSSLAsymmetricKey $privateKey = null;

    private static ?\OpenSSLAsymmetricKey $otherKey = null;

    /**
     * @param  list<string>  $mobileClientIds
     */
    private function enableGoogle(array $mobileClientIds = ['android-client']): void
    {
        config([
            'authentication.google.enabled' => true,
            'services.google.client_id' => 'web-client',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.mobile_client_ids' => $mobileClientIds,
            'services.google.jwks_uri' => self::JWKS_URI,
        ]);

        Cache::forget('api.google.jwks');
    }

    private function fakeJwks(?\OpenSSLAsymmetricKey $key = null): void
    {
        $key ??= self::privateKey();
        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Não foi possível ler a chave de teste.');
        }

        Http::fake([
            self::JWKS_URI => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => self::KID,
                    'n' => self::base64Url($details['rsa']['n']),
                    'e' => self::base64Url($details['rsa']['e']),
                ]],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function idToken(array $claims = [], ?\OpenSSLAsymmetricKey $key = null): string
    {
        $payload = $claims + [
            'iss' => 'https://accounts.google.com',
            'aud' => 'android-client',
            'sub' => 'google-sub-1',
            'email' => 'cliente.google@example.com',
            'name' => 'Cliente Google',
            'email_verified' => true,
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
        ];

        return JWT::encode($payload, $key ?? self::privateKey(), 'RS256', self::KID);
    }

    public function test_google_login_creates_a_verified_client_and_returns_a_token(): void
    {
        Notification::fake();
        $this->enableGoogle();
        $this->fakeJwks();

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(),
            'device_name' => 'iPhone 15',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['client'])
            ->assertJsonPath('data.user.email', 'cliente.google@example.com')
            ->assertJsonPath('data.user.name', 'Cliente Google')
            ->assertJsonPath('data.user.role', UserRole::Client->value);

        $user = User::query()->where('email', 'cliente.google@example.com')->sole();

        $this->assertSame('google-sub-1', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->wallet);
        $this->assertNotNull($response->json('data.token'));
    }

    public function test_google_links_an_existing_email_without_duplicating_the_account(): void
    {
        Notification::fake();
        $this->enableGoogle();
        $this->fakeJwks();

        $user = User::factory()->unverified()->create([
            'email' => 'cliente.existente@gmail.com',
            'phone' => '11988887777',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'email' => 'CLIENTE.EXISTENTE@GMAIL.COM',
                'sub' => 'google-sub-existing',
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.abilities', ['client']);

        $user->refresh();

        $this->assertSame('google-sub-existing', $user->google_id);
        $this->assertSame('11988887777', $user->phone);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_logs_in_a_linked_account_without_creating_another_user(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $user = User::factory()->create([
            'email' => 'cliente.recorrente@gmail.com',
            'google_id' => 'google-sub-returning',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'sub' => 'google-sub-returning',
                'email' => 'cliente.recorrente@gmail.com',
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_rejects_an_email_already_linked_to_another_google_account(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        User::factory()->create([
            'email' => 'cliente.conflito@gmail.com',
            'google_id' => 'google-sub-original',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'sub' => 'google-sub-diferente',
                'email' => 'cliente.conflito@gmail.com',
            ]),
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Este e-mail já está vinculado a outra conta Google. Entre com e-mail e senha.',
            );

        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_blocks_inactive_accounts(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        User::factory()->create([
            'email' => 'cliente.bloqueado@gmail.com',
            'google_id' => 'google-sub-blocked',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'sub' => 'google-sub-blocked',
                'email' => 'cliente.bloqueado@gmail.com',
            ]),
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Esta conta está desativada. Fale com o suporte para reativá-la.',
            );
    }

    public function test_google_does_not_issue_a_token_for_an_admin_account(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        User::factory()->admin()->create([
            'email' => 'admin.google@example.com',
            'google_id' => 'google-sub-admin',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'sub' => 'google-sub-admin',
                'email' => 'admin.google@example.com',
            ]),
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'O aplicativo móvel é exclusivo para clientes e atendentes.',
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_rejects_a_token_with_an_unverified_email(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(['email_verified' => false]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_rejects_a_token_issued_for_another_audience(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(['aud' => 'outro-aplicativo']),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_rejects_a_token_with_an_invalid_signature(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([], self::otherKey()),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_rejects_an_expired_token(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'iat' => now()->subHours(2)->timestamp,
                'exp' => now()->subHour()->timestamp,
            ]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_rejects_an_untrusted_issuer(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(['iss' => 'https://example.com']),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_ignores_email_and_name_sent_in_the_request_body(): void
    {
        $this->enableGoogle();
        $this->fakeJwks();

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken([
                'sub' => 'google-sub-signed',
                'email' => 'identidade.assinada@gmail.com',
                'name' => 'Nome Assinado',
            ]),
            'email' => 'invasor@example.com',
            'name' => 'Invasor',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'identidade.assinada@gmail.com')
            ->assertJsonPath('data.user.name', 'Nome Assinado');

        $this->assertDatabaseMissing('users', ['email' => 'invasor@example.com']);
    }

    public function test_google_fails_closed_when_the_jwks_is_unavailable(): void
    {
        $this->enableGoogle();
        Http::fake([self::JWKS_URI => Http::response([], 500)]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_is_unavailable_without_any_configured_client_id(): void
    {
        config([
            'authentication.google.enabled' => true,
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.google.mobile_client_ids' => [],
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'token-qualquer'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'O login com Google não está disponível no aplicativo.');
    }

    public function test_new_google_accounts_receive_the_first_access_bonus(): void
    {
        Notification::fake();
        $this->enableGoogle();
        $this->fakeJwks();

        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 2,
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '250',
            'type' => 'integer',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(['sub' => 'google-sub-bonus']),
        ])->assertOk();

        $user = User::query()->where('email', 'cliente.google@example.com')->sole();

        $this->assertSame(500, $user->wallet->fresh()->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_returning_google_accounts_do_not_receive_the_bonus_again(): void
    {
        Notification::fake();
        $this->enableGoogle();
        $this->fakeJwks();

        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 2,
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '250',
            'type' => 'integer',
        ]);

        User::factory()->create([
            'email' => 'cliente.google@example.com',
            'google_id' => 'google-sub-returning',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->idToken(['sub' => 'google-sub-returning']),
        ])->assertOk();

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    private static function privateKey(): \OpenSSLAsymmetricKey
    {
        return self::$privateKey ??= self::generateKey();
    }

    private static function otherKey(): \OpenSSLAsymmetricKey
    {
        return self::$otherKey ??= self::generateKey();
    }

    private static function generateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Não foi possível gerar a chave RSA de teste.');
        }

        return $key;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
