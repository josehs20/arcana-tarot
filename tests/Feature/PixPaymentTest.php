<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\PaymentLimits;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PixPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-sandbox-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.provider' => 'pix_sandbox',
            'payments.sandbox.enabled' => true,
            'payments.sandbox.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.sandbox.webhook_tolerance_seconds' => 300,
            'payments.sandbox.charge_ttl_minutes' => 30,
            'payments.min_amount_cents' => 100,
            'payments.max_amount_cents' => 1_000_000,
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '100',
            'type' => 'integer',
        ]);
    }

    private function createRecharge(User $client, int $amountCents = 10_000, ?string $operationId = null): Payment
    {
        $operationId ??= (string) Str::uuid();

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_cents' => $amountCents,
                'operation_id' => $operationId,
            ])
            ->assertRedirect();

        return Payment::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function sendWebhook(
        Payment $payment,
        string $eventId,
        string $providerStatus = 'approved',
        ?int $amountCents = null,
        ?int $timestamp = null,
        ?string $secret = null,
        ?string $providerPaymentId = null,
    ): TestResponse {
        $payload = [
            'id' => $eventId,
            'event' => 'payment.updated',
            'payment' => [
                'id' => $providerPaymentId ?? $payment->provider_payment_id,
                'status' => $providerStatus,
                'amount_cents' => $amountCents ?? $payment->amount_cents,
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$eventId.'.'.$body,
            $secret ?? self::WEBHOOK_SECRET,
        );

        return $this->call(
            'POST',
            '/webhooks/pix',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ARCANA_EVENT_ID' => $eventId,
                'HTTP_X_ARCANA_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ARCANA_SIGNATURE' => 'v1='.$signature,
            ],
            content: $body,
        );
    }

    public function test_client_creates_pending_recharge_with_sandbox_instructions(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_reais' => '100,00',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('pix_sandbox', $payment->provider);
        $this->assertSame(10_000, $payment->amount_cents);
        $this->assertSame(100, $payment->credits);
        $this->assertSame(100, $payment->credit_unit_price_cents);
        $this->assertNotNull($payment->provider_payment_id);
        $this->assertNotNull($payment->external_reference);
        $this->assertNotNull($payment->expires_at);
        $this->assertStringContainsString('SANDBOX-NAO-E-PIX', $payment->pix_copy_paste);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));

        $rawCopyPaste = DB::table('payments')->where('id', $payment->id)->value('pix_copy_paste');
        $this->assertNotSame($payment->pix_copy_paste, $rawCopyPaste);
        $this->assertStringNotContainsString('SANDBOX-NAO-E-PIX', (string) $rawCopyPaste);

        $this->actingAs($client)
            ->get(route('client.credits.payments.show', $payment))
            ->assertOk()
            ->assertSee('Aguardando pagamento')
            ->assertSee('SANDBOX-NAO-E-PIX')
            ->assertSee('não pague este código')
            ->assertSee('pix:sandbox-simulate');
    }

    public function test_recharge_amount_must_be_a_multiple_of_the_credit_price(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_cents' => 1_050,
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('amount_cents');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_creation_is_idempotent_by_operation_id(): void
    {
        $client = User::factory()->create();
        $operationId = (string) Str::uuid();

        $first = $this->createRecharge($client, 10_000, $operationId);
        $second = $this->createRecharge($client, 10_000, $operationId);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_webhook_route_bypasses_csrf_and_keeps_rate_limit(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.pix');

        $this->assertNotNull($route);
        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
        $this->assertContains('throttle:120,1', $route->middleware());
    }

    public function test_recharge_is_unavailable_when_sandbox_secret_is_missing(): void
    {
        config(['payments.sandbox.webhook_secret' => '']);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_cents' => 10_000,
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_approved_webhook_confirms_payment_and_credits_wallet_once(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_approved_1')
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => false]);

        $payment->refresh();
        $wallet = $client->fresh()->wallet;
        $entry = CreditLedgerEntry::query()->firstOrFail();
        $event = PaymentWebhookEvent::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(10_000, $wallet?->balance_cents);
        $this->assertSame(10_000, $entry->amount_cents);
        $this->assertSame(LedgerEntryType::Credit, $entry->type);
        $this->assertSame(Payment::class, $entry->reference_type);
        $this->assertSame($payment->id, $entry->reference_id);
        $this->assertSame('payment:'.$payment->id, $entry->idempotency_key);
        $this->assertSame(PaymentEventStatus::Processed, $event->status);
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame(64, strlen($event->payload_hash));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.confirmed',
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
        ]);
    }

    public function test_repeated_webhook_does_not_duplicate_credit(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_repeat_1')->assertOk();
        $this->sendWebhook($payment, 'evt_repeat_1')
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => true]);

        $this->assertSame(10_000, $client->fresh()->wallet?->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
    }

    public function test_a_new_approval_event_after_confirmation_is_ignored(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_again_1')->assertOk();
        $this->sendWebhook($payment, 'evt_again_2')->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(10_000, $client->fresh()->wallet?->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(2, PaymentWebhookEvent::query()->count());
        $this->assertSame(
            1,
            PaymentWebhookEvent::query()
                ->where('status', PaymentEventStatus::Ignored->value)
                ->count(),
        );
    }

    public function test_declined_webhook_marks_payment_failed_without_credit(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_declined_1', 'declined')->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('provider_declined', $payment->failure_reason);
        $this->assertNull($payment->paid_at);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.declined',
            'subject_id' => $payment->id,
        ]);
    }

    public function test_expired_webhook_marks_payment_expired_without_credit(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_expired_1', 'expired')->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame('provider_expired', $payment->failure_reason);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.expired',
            'subject_id' => $payment->id,
        ]);
    }

    public function test_invalid_signature_is_rejected_without_state_change(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_invalid_signature', secret: 'wrong-secret')
            ->assertStatus(401)
            ->assertJson(['received' => false]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_stale_webhook_is_rejected_even_with_a_valid_signature(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook(
            $payment,
            'evt_stale_1',
            timestamp: now()->subMinutes(10)->getTimestamp(),
        )->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_amount_mismatch_is_rejected_and_audited_without_credit(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->sendWebhook($payment, 'evt_amount_mismatch', amountCents: 9_999)
            ->assertStatus(422)
            ->assertJson(['received' => false]);

        $event = PaymentWebhookEvent::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentEventStatus::Failed, $event->status);
        $this->assertSame('amount_mismatch', $event->failure_reason);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.webhook_rejected',
            'subject_id' => $payment->id,
        ]);
    }

    public function test_client_cannot_view_another_clients_recharge(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $payment = $this->createRecharge($owner);

        $this->actingAs($other)
            ->get(route('client.credits.payments.show', $payment))
            ->assertForbidden();
    }

    public function test_attendant_cannot_create_a_recharge(): void
    {
        $attendant = User::factory()->attendant()->create();

        $this->actingAs($attendant)
            ->post(route('client.credits.store'), [
                'amount_cents' => 10_000,
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_pending_page_does_not_change_status_by_browser_redirect(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->actingAs($client)
            ->get(route('client.credits.payments.show', $payment).'?status=approved')
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_sandbox_command_sends_an_authenticated_webhook(): void
    {
        $client = User::factory()->create();
        $payment = $this->createRecharge($client);

        $this->artisan('pix:sandbox-simulate', [
            'payment' => $payment->id,
            'status' => 'approved',
        ])->assertExitCode(0);

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(10_000, $client->fresh()->wallet?->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_free_recharge_range_comes_from_the_admin_configured_limits(): void
    {
        Setting::query()->create(['key' => PaymentLimits::MIN_SETTING_KEY, 'value' => '1000', 'type' => 'integer']);
        Setting::query()->create(['key' => PaymentLimits::MAX_SETTING_KEY, 'value' => '5000', 'type' => 'integer']);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_reais' => '5,00',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('amount_cents');

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_reais' => '80,00',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('amount_cents');

        $this->assertSame(0, Payment::query()->count());

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'amount_reais' => '30,00',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $payment = Payment::query()->sole();

        $this->assertSame(3_000, $payment->amount_cents);
        $this->assertSame(30, $payment->credits);
    }
}
