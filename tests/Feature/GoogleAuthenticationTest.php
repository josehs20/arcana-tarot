<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use Tests\TestCase;

/**
 * Login e cadastro com Google (OAuth 2.0).
 *
 * O driver do Socialite é substituído por um dublê: nenhuma chamada real é
 * feita ao Google e o teste controla o `sub`, o e-mail e a claim
 * `email_verified` devolvidos pelo userinfo.
 */
class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function enableGoogle(): void
    {
        config([
            'authentication.google.enabled' => true,
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => url('/auth/google/callback'),
        ]);
    }

    /**
     * @param  array{id?: string, email?: string, name?: string, raw?: array<string, mixed>}  $attributes
     */
    private function fakeGoogleUser(array $attributes = [], ?\Throwable $failure = null): SocialiteProvider
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($attributes['id'] ?? 'google-sub-1');
        $socialiteUser->shouldReceive('getEmail')->andReturn($attributes['email'] ?? 'cliente.google@example.com');
        $socialiteUser->shouldReceive('getName')->andReturn($attributes['name'] ?? 'Cliente Google');
        $socialiteUser->shouldReceive('getRaw')->andReturn($attributes['raw'] ?? ['email_verified' => true]);

        $provider = Mockery::mock(SocialiteProvider::class);

        if ($failure !== null) {
            $provider->shouldReceive('user')->andThrow($failure);
        } else {
            $provider->shouldReceive('user')->andReturn($socialiteUser);
        }

        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth?state=test'));

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->app->instance(SocialiteFactory::class, $factory);

        return $provider;
    }

    public function test_google_login_is_hidden_and_blocked_until_it_is_configured(): void
    {
        // Sem isso o resultado depende do .env de quem roda a suíte: com
        // credenciais preenchidas o botão aparece e o teste falha.
        config([
            'authentication.google.enabled' => true,
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Continuar com Google');

        $this->get(route('auth.google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->fakeGoogleUser();

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_google_redirect_sends_the_client_to_the_provider(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleUser();

        $this->get(route('login'))->assertOk()->assertSee('Continuar com Google');

        $this->get(route('auth.google.redirect'))
            ->assertRedirectContains('accounts.google.com');
    }

    public function test_google_callback_creates_and_authenticates_a_new_client(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleUser(['id' => 'google-sub-new', 'email' => 'Nova.Conta@Example.com', 'name' => 'Ana Souza']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('client.dashboard'))
            ->assertSessionHas('success');

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'nova.conta@example.com')->sole();

        $this->assertSame('Ana Souza', $user->name);
        $this->assertSame('google-sub-new', $user->google_id);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->wallet);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_callback_links_an_existing_account_created_with_password(): void
    {
        $this->enableGoogle();

        $existing = User::factory()->create([
            'email' => 'cliente.antigo@example.com',
            'password' => 'senha-antiga',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser(['id' => 'google-sub-link', 'email' => 'cliente.antigo@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('client.dashboard'));

        $existing->refresh();

        $this->assertSame('google-sub-link', $existing->google_id);
        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('senha-antiga', $existing->password));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_google_callback_brings_a_published_attendant_online(): void
    {
        $this->enableGoogle();

        $attendant = User::factory()->attendant()->create([
            'email' => 'atendente.google@example.com',
            'google_id' => null,
        ]);
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create();

        $this->fakeGoogleUser(['id' => 'google-sub-attendant', 'email' => 'atendente.google@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('attendant.dashboard'));

        $this->assertAuthenticatedAs($attendant);
        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
    }

    public function test_google_callback_rejects_an_email_already_linked_to_another_google_account(): void
    {
        $this->enableGoogle();

        User::factory()->create([
            'email' => 'cliente.antigo@example.com',
            'google_id' => 'google-sub-outro',
        ]);

        $this->fakeGoogleUser(['id' => 'google-sub-novo', 'email' => 'cliente.antigo@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('google-sub-outro', User::query()->sole()->google_id);
    }

    public function test_google_callback_blocks_inactive_accounts(): void
    {
        $this->enableGoogle();

        User::factory()->create([
            'email' => 'bloqueado@example.com',
            'google_id' => 'google-sub-blocked',
            'is_active' => false,
        ]);

        $this->fakeGoogleUser(['id' => 'google-sub-blocked', 'email' => 'bloqueado@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_google_callback_rejects_an_unverified_google_email(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleUser(['raw' => ['email_verified' => false]]);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_callback_restarts_the_flow_when_the_oauth_state_is_invalid(): void
    {
        $this->enableGoogle();

        $this->fakeGoogleUser([], new InvalidStateException);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_google_callback_handles_provider_failures_generically(): void
    {
        $this->enableGoogle();

        $this->fakeGoogleUser([], new \RuntimeException('token exchange failed'));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_google_accounts_receive_the_first_access_bonus(): void
    {
        $this->enableGoogle();

        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 2,
        ]);

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '250', 'type' => 'integer']);

        $this->fakeGoogleUser(['id' => 'google-sub-bonus', 'email' => 'bonus@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('client.dashboard'));

        $user = User::query()->where('email', 'bonus@example.com')->sole();

        $this->assertSame(500, $user->wallet->fresh()->balance_cents);
    }

    public function test_existing_accounts_do_not_receive_the_bonus_again(): void
    {
        $this->enableGoogle();

        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 2,
        ]);

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '250', 'type' => 'integer']);

        User::factory()->create([
            'email' => 'cliente.antigo@example.com',
            'google_id' => 'google-sub-returning',
        ]);

        $this->fakeGoogleUser(['id' => 'google-sub-returning', 'email' => 'cliente.antigo@example.com']);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('client.dashboard'));

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }
}
