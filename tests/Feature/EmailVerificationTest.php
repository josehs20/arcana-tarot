<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cadastro com confirmação de e-mail.
 *
 * O POST /register cria a conta e já autentica o cliente, mas os painéis ficam
 * bloqueados pelo middleware `verified` até o link assinado ser aberto. É a
 * confirmação — não o cadastro — que libera o acesso e concede o bônus de
 * primeiro acesso (coberto em FirstAccessBonusTest).
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Cliente E-mail',
            'email' => 'cliente.verificacao@gmail.com',
            'phone' => '(11) 97777-6666',
            'birth_date' => '1990-05-20',
            'gender' => Gender::Female->value,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ];
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($minutes),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    private function unverifiedClient(): User
    {
        return User::factory()->unverified()->create();
    }

    public function test_registration_creates_the_account_and_sends_the_verification_link(): void
    {
        Notification::fake();

        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'cliente.verificacao@gmail.com')->sole();

        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->wallet);

        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
            function (VerifyEmailNotification $notification, array $channels) use ($user): bool {
                $mail = $notification->toMail($user);

                return $mail->view === 'mail.verify-email'
                    && str_contains(
                        (string) $mail->viewData['verificationUrl'],
                        'email/verificar/'.$user->getKey().'/',
                    )
                    && $mail->viewData['recipientEmail'] === $user->email;
            },
        );
    }

    public function test_the_domain_check_ignores_the_case(): void
    {
        $this->post('/register', $this->registrationPayload(['email' => ' Cliente.Verificacao@GMAIL.COM ']))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', ['email' => 'cliente.verificacao@gmail.com']);
    }

    public function test_registration_accepts_only_the_configured_domains(): void
    {
        $this->post('/register', $this->registrationPayload(['email' => 'cliente@yahoo.com']))
            ->assertSessionHasErrors([
                'email' => 'Aceitamos apenas e-mails @gmail.com, @hotmail.com, @outlook.com ou @ig.com.br.',
            ]);

        // Domínio parecido e subdomínio não passam: a comparação é exata.
        foreach (['cliente@gmail.com.br', 'cliente@sub.gmail.com', 'cliente@example.com'] as $email) {
            $this->post('/register', $this->registrationPayload(['email' => $email]))
                ->assertSessionHasErrors('email');
        }

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_allowed_domain_list_comes_from_configuration(): void
    {
        config(['authentication.registration.allowed_email_domains' => ['empresa.com.br']]);

        $this->post('/register', $this->registrationPayload(['email' => 'cliente@gmail.com']))
            ->assertSessionHasErrors('email');

        $this->post('/register', $this->registrationPayload(['email' => 'cliente@empresa.com.br']))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', ['email' => 'cliente@empresa.com.br']);
    }

    public function test_the_notice_shows_the_destination_and_the_resend_button(): void
    {
        $user = $this->unverifiedClient();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Confirme seu e-mail')
            ->assertSee($user->email)
            ->assertSee(route('verification.send'), false);
    }

    public function test_the_signed_link_verifies_the_email_and_unlocks_the_panel(): void
    {
        $user = $this->unverifiedClient();

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->get($this->verificationUrl($user))
            ->assertRedirect(route('client.dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(route('client.dashboard'))->assertOk();
    }

    public function test_the_link_is_rejected_without_the_signature_or_with_the_wrong_hash(): void
    {
        $user = $this->unverifiedClient();

        $this->actingAs($user);

        // URL assinada, mas com o hash de outro endereço.
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1('outra.pessoa@gmail.com'),
        ]))->assertForbidden();

        // URL sem assinatura nenhuma.
        $this->get(route('verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->email),
        ]))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_link_expires_after_the_configured_window(): void
    {
        $user = $this->unverifiedClient();
        $url = $this->verificationUrl($user);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_users_are_blocked_from_the_panels_but_can_log_out(): void
    {
        $user = $this->unverifiedClient();

        $this->actingAs($user)
            ->get(route('client.credits.index'))
            ->assertRedirect(route('verification.notice'));

        $this->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_the_resend_is_rate_limited(): void
    {
        Notification::fake();

        $user = $this->unverifiedClient();

        $this->actingAs($user);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post(route('verification.send'))->assertRedirect();
        }

        $this->post(route('verification.send'))->assertTooManyRequests();

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 6);
    }

    public function test_verified_users_skip_the_notice_and_the_resend(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertRedirect(route('client.dashboard'));

        $this->post(route('verification.send'))
            ->assertRedirect(route('client.dashboard'));

        Notification::assertNothingSent();
    }

    public function test_login_of_an_unverified_account_ends_on_the_notice(): void
    {
        $user = $this->unverifiedClient();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
    }
}
