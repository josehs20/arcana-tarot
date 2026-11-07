<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\PhoneVerificationStatus;
use App\Enums\SmsVerificationResult;
use App\Enums\UserRole;
use App\Models\CreditLedgerEntry;
use App\Models\PhoneVerification;
use App\Models\Setting;
use App\Models\User;
use App\Support\FirstAccessBonus;
use App\Support\Sms\Contracts\SmsVerificationProvider;
use App\Support\Sms\SmsVerificationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cadastro em duas etapas com confirmação do celular por SMS.
 *
 * O provedor é substituído por um dublê para que os testes não dependam do
 * Twilio: o contrato só recebe o número em E.164 e devolve o resultado da
 * conferência.
 */
class SmsRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsVerificationProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsVerificationProvider;
        $this->app->instance(SmsVerificationProvider::class, $this->sms);

        config([
            'authentication.sms.enabled' => true,
            'authentication.sms.code_length' => 6,
            'authentication.sms.ttl_minutes' => 10,
            'authentication.sms.max_attempts' => 5,
            'authentication.sms.resend_cooldown_seconds' => 60,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cliente SMS',
            'email' => 'cliente.sms@example.com',
            'phone' => '(11) 99999-9999',
            'birth_date' => '1990-08-14',
            'gender' => Gender::Female->value,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ], $overrides);
    }

    private function startVerification(): PhoneVerification
    {
        $this->post('/register', $this->registrationPayload())->assertRedirect(route('register.verify'));

        return PhoneVerification::query()->sole();
    }

    public function test_registration_is_only_completed_after_the_code_is_approved(): void
    {
        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('register.verify'))
            ->assertSessionHas('status');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente.sms@example.com']);
        $this->assertSame(['+5511999999999'], $this->sms->sent);

        $verification = PhoneVerification::query()->sole();

        $this->assertSame(PhoneVerificationStatus::Pending, $verification->status);
        $this->assertSame('cliente.sms@example.com', $verification->registrationPayload()['email']);
        $this->assertSame($verification->id, session(config('authentication.sms.session_key')));

        $this->get(route('register.verify'))
            ->assertOk()
            ->assertSee('Confirme seu celular')
            ->assertSee('(11) *****-9999')
            ->assertDontSee('cliente.sms@example.com');

        $this->sms->result = SmsVerificationResult::Approved;

        $this->post(route('register.verify.store'), ['code' => '123 456'])
            ->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'cliente.sms@example.com')->sole();

        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('11999999999', $user->phone);
        $this->assertSame('1990-08-14', $user->birth_date?->format('Y-m-d'));
        $this->assertSame(Gender::Female, $user->gender);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->wallet);
        $this->assertTrue(Hash::check('senha-segura', $user->password));

        $this->assertSame('123456', $this->sms->checkedCodes[0]);

        $verification->refresh();
        $this->assertSame(PhoneVerificationStatus::Approved, $verification->status);
        $this->assertNotNull($verification->consumed_at);
        $this->assertNull(session(config('authentication.sms.session_key')));
    }

    public function test_an_invalid_code_keeps_the_registration_pending_and_counts_attempts(): void
    {
        $verification = $this->startVerification();

        $this->sms->result = SmsVerificationResult::Invalid;

        $this->post(route('register.verify.store'), ['code' => '000000'])
            ->assertRedirect(route('register.verify'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente.sms@example.com']);

        $verification->refresh();
        $this->assertSame(PhoneVerificationStatus::Pending, $verification->status);
        $this->assertSame(1, $verification->attempts);
    }

    public function test_reaching_the_attempt_limit_invalidates_the_verification(): void
    {
        config(['authentication.sms.max_attempts' => 1]);

        $verification = $this->startVerification();

        $this->sms->result = SmsVerificationResult::Invalid;

        $this->post(route('register.verify.store'), ['code' => '000000'])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('phone');

        $verification->refresh();
        $this->assertSame(PhoneVerificationStatus::Failed, $verification->status);
        $this->assertDatabaseMissing('users', ['email' => 'cliente.sms@example.com']);
    }

    public function test_an_expired_code_requires_a_new_verification(): void
    {
        $verification = $this->startVerification();

        $verification->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('register.verify'))
            ->assertOk()
            ->assertSee('O código anterior expirou');

        $this->post(route('register.verify.store'), ['code' => '123456'])
            ->assertRedirect(route('register.verify'))
            ->assertSessionHasErrors('code');

        $verification->refresh();
        $this->assertSame(PhoneVerificationStatus::Expired, $verification->status);
        $this->assertSame([], $this->sms->checkedCodes);

        $this->get(route('register.verify'))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('phone');
    }

    public function test_code_with_the_wrong_length_is_rejected_before_the_provider(): void
    {
        $verification = $this->startVerification();

        $this->post(route('register.verify.store'), ['code' => '123'])
            ->assertSessionHasErrors('code');

        $this->assertSame([], $this->sms->checkedCodes);
        $this->assertSame(0, $verification->fresh()->attempts);
    }

    public function test_resend_waits_for_the_cooldown_and_resets_attempts(): void
    {
        $verification = $this->startVerification();
        $verification->forceFill(['attempts' => 3])->save();

        $this->post(route('register.verify.resend'))
            ->assertRedirect(route('register.verify'))
            ->assertSessionHasErrors('code');

        $this->assertCount(1, $this->sms->sent);

        $this->travel(61)->seconds();

        $this->post(route('register.verify.resend'))
            ->assertRedirect(route('register.verify'))
            ->assertSessionHas('status');

        $this->assertCount(2, $this->sms->sent);

        $verification->refresh();
        $this->assertSame(PhoneVerificationStatus::Pending, $verification->status);
        $this->assertSame(0, $verification->attempts);
        $this->assertTrue($verification->expires_at->isFuture());
    }

    public function test_provider_failure_blocks_the_registration_with_a_friendly_error(): void
    {
        $this->sms->failOnSend = true;

        $this->from(route('register'))
            ->post('/register', $this->registrationPayload())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'cliente.sms@example.com']);
        $this->assertSame(PhoneVerificationStatus::Failed, PhoneVerification::query()->sole()->status);
        $this->assertNull(session(config('authentication.sms.session_key')));
    }

    public function test_provider_failure_while_checking_the_code_keeps_the_pending_registration(): void
    {
        $verification = $this->startVerification();

        $this->sms->failOnCheck = true;

        $this->post(route('register.verify.store'), ['code' => '123456'])
            ->assertRedirect(route('register.verify'))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('users', ['email' => 'cliente.sms@example.com']);
        $this->assertSame(PhoneVerificationStatus::Pending, $verification->fresh()->status);
    }

    public function test_the_verification_screen_requires_a_pending_verification_in_the_session(): void
    {
        $this->get(route('register.verify'))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('phone');
    }

    public function test_sms_disabled_keeps_the_single_step_registration(): void
    {
        config(['authentication.sms.enabled' => false]);

        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticated();
        $this->assertSame([], $this->sms->sent);
        $this->assertDatabaseHas('users', ['email' => 'cliente.sms@example.com']);
        $this->assertSame(0, PhoneVerification::query()->count());
    }

    public function test_first_access_bonus_is_granted_only_after_the_code_is_approved(): void
    {
        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 4,
        ]);

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);

        $verification = $this->startVerification();

        $this->assertSame(0, CreditLedgerEntry::query()->count());

        $this->post(route('register.verify.store'), ['code' => '123456'])
            ->assertRedirect(route('client.dashboard'));

        $user = User::query()->where('email', 'cliente.sms@example.com')->sole();

        $this->assertSame(600, $user->wallet->fresh()->balance_cents);

        $entry = CreditLedgerEntry::query()->sole();
        $this->assertSame(600, $entry->amount_cents);
        $this->assertSame(FirstAccessBonus::idempotencyKeyFor($user), $entry->idempotency_key);

        $this->assertSame(PhoneVerificationStatus::Approved, $verification->fresh()->status);
    }
}

/**
 * Dublê do provedor de SMS: registra envios e devolve o resultado configurado.
 */
final class FakeSmsVerificationProvider implements SmsVerificationProvider
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    public array $checkedCodes = [];

    public SmsVerificationResult $result = SmsVerificationResult::Approved;

    public bool $failOnSend = false;

    public bool $failOnCheck = false;

    public function send(string $e164Phone): void
    {
        if ($this->failOnSend) {
            throw SmsVerificationException::sendFailed();
        }

        $this->sent[] = $e164Phone;
    }

    public function check(string $e164Phone, string $code): SmsVerificationResult
    {
        if ($this->failOnCheck) {
            throw SmsVerificationException::checkFailed();
        }

        $this->checkedCodes[] = $code;

        return $this->result;
    }
}
