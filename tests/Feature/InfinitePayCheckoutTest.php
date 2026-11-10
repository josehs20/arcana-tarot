<?php

namespace Tests\Feature;

use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\CreditPackages;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\InfinitePay\Data\CheckoutLinkRequest;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checkout Integrado da InfinitePay.
 *
 * Cobre o caminho crítico: o link é criado no servidor a partir de um pacote do
 * catálogo e nenhum crédito é liberado sem `payment_check` aprovado, mesmo que
 * o webhook seja repetido, divergente ou forjado.
 */
class InfinitePayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const LINKS_URL = 'https://api.checkout.infinitepay.io/links';

    private const CHECK_URL = 'https://api.checkout.infinitepay.io/payment_check';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.provider' => 'infinitepay',
            'payments.infinitepay.handle' => 'arcana',
            'payments.infinitepay.api_key' => null,
            'payments.infinitepay.base_url' => 'https://api.checkout.infinitepay.io',
            'payments.infinitepay.webhook_url' => 'https://arcana.test/webhooks/infinitepay',
            'payments.infinitepay.charge_ttl_minutes' => 1440,
            'payments.infinitepay.timeout_seconds' => 5,
            'payments.packages' => [
                'credits_10' => 10,
                'credits_100' => 100,
            ],
            'payments.min_amount_cents' => 100,
            'payments.max_amount_cents' => 1_000_000,
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '100',
            'type' => 'integer',
        ]);

        Http::preventStrayRequests();
    }

    private function fakeCheckoutLink(): void
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'url' => 'https://checkout.infinitepay.io/abc123',
                'invoice_slug' => 'slug-abc123',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakePaymentCheck(array $payload): void
    {
        Http::fake([
            self::CHECK_URL => Http::response($payload),
        ]);
    }

    private function startCheckout(User $client, string $package = 'credits_10'): Payment
    {
        $this->fakeCheckoutLink();

        $this->actingAs($client)
            ->post(route('client.credits.checkout.store'), [
                'package' => $package,
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect('https://checkout.infinitepay.io/abc123');

        return Payment::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendWebhook(Payment $payment, array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'order_nsu' => $payment->external_reference,
            'transaction_nsu' => 'txn-'.$payment->getKey(),
            'invoice_slug' => 'slug-abc123',
            'amount' => $payment->amount_cents,
        ], $overrides);

        return $this->postJson(route('webhooks.infinitepay'), $payload);
    }

    public function test_client_checkout_creates_pending_payment_with_server_side_amount(): void
    {
        $client = User::factory()->create();

        $payment = $this->startCheckout($client);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('infinitepay', $payment->provider);
        $this->assertSame('credits_10', $payment->package_key);
        $this->assertSame(10, $payment->credits);
        $this->assertSame(1_000, $payment->amount_cents);
        $this->assertSame('https://checkout.infinitepay.io/abc123', $payment->checkout_url);
        $this->assertSame('slug-abc123', $payment->invoice_slug);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === self::LINKS_URL
                && $request['handle'] === 'arcana'
                && $request['order_nsu'] !== ''
                && $request['items'][0]['price'] === 1_000
                && $request['webhook']['url'] === 'https://arcana.test/webhooks/infinitepay'
                && $request['webhook']['events'] === [
                    'created',
                    'paid',
                    'settled',
                    'canceled',
                    'refunded',
                    'payment_failed',
                ];
        });
    }

    public function test_repeated_checkout_with_same_operation_reuses_the_payment(): void
    {
        $client = User::factory()->create();
        $operationId = (string) Str::uuid();

        $this->fakeCheckoutLink();

        foreach ([1, 2] as $attempt) {
            $this->actingAs($client)
                ->post(route('client.credits.checkout.store'), [
                    'package' => 'credits_10',
                    'operation_id' => $operationId,
                ])
                ->assertRedirect('https://checkout.infinitepay.io/abc123');
        }

        $this->assertSame(1, Payment::query()->count());
        Http::assertSentCount(1);
    }

    public function test_unknown_package_is_rejected_before_calling_the_provider(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.checkout.store'), [
                'package' => 'credits_999999',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('package');

        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_checkout_link_omits_the_customer_email_when_the_domain_is_reserved(): void
    {
        // Contas de demonstração usam `*.local`, que o validador do provedor
        // recusa; o e-mail é omitido para não derrubar a cobrança inteira.
        $client = User::factory()->create(['email' => 'cliente.livre@arcana.local']);

        $this->startCheckout($client);

        Http::assertSent(function (HttpRequest $request) use ($client): bool {
            return $request->url() === self::LINKS_URL
                && $request['customer']['name'] === $client->name
                && ! array_key_exists('email', $request['customer']);
        });
    }

    public function test_checkout_link_sends_the_customer_email_when_the_domain_is_public(): void
    {
        $client = User::factory()->create(['email' => 'cliente.arcana@gmail.com']);

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['customer']['email'] === 'cliente.arcana@gmail.com');
    }

    public function test_checkout_link_sends_the_customer_phone_with_the_brazilian_country_code(): void
    {
        // O telefone é persistido só com DDD + assinante; a InfinitePay usa o
        // DDI do `customer.phone_number` para escolher o país (campo travado no
        // checkout), então o gateway precisa enviar `+5511999999999`.
        $client = User::factory()->create(['phone' => '11999999999']);

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['customer']['phone_number'] === '+5511999999999');
    }

    public function test_checkout_link_drops_a_stored_country_code_from_the_customer_phone(): void
    {
        // Bases antigas podem ter o DDI gravado junto do número.
        $client = User::factory()->create(['phone' => '5511999999999']);

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['customer']['phone_number'] === '+5511999999999');
    }

    public function test_checkout_link_keeps_the_area_code_55_in_the_customer_phone(): void
    {
        $client = User::factory()->create(['phone' => '55999999999']);

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['customer']['phone_number'] === '+5555999999999');
    }

    public function test_checkout_link_omits_the_customer_phone_when_it_is_not_a_valid_brazilian_number(): void
    {
        $client = User::factory()->create(['phone' => '1199999']);

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && ! array_key_exists('phone_number', $request['customer']));
    }

    public function test_checkout_link_skips_the_delivery_address_when_the_api_key_is_configured(): void
    {
        // Créditos são digitais: sem endereço de entrega no checkout.
        config(['payments.infinitepay.api_key' => 'chave-de-teste']);

        $client = User::factory()->create();
        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['options']['require_delivery_address'] === false
            && $request['options']['single_payment'] === true
            && is_string($request['options']['valid_until'] ?? null));
    }

    public function test_checkout_link_omits_options_without_the_api_key(): void
    {
        $client = User::factory()->create();

        $this->startCheckout($client);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && ! array_key_exists('options', $request->data()));
    }

    public function test_gateway_exception_carries_the_provider_validation_details(): void
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'success' => false,
                'message' => 'Invalid checkout link params',
                'errors' => ['customer' => ['email' => ['must be a valid email']]],
            ], 422),
        ]);

        $this->expectException(InfinitePayException::class);
        $this->expectExceptionMessage('Invalid checkout link params | must be a valid email');

        app(InfinitePayGateway::class)->createCheckoutLink(new CheckoutLinkRequest(
            orderNsu: 'recharge_teste',
            amountCents: 1_000,
            description: 'Recarga de teste',
        ));
    }

    public function test_rejected_link_marks_the_payment_as_failed_without_credits(): void
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'success' => false,
                'message' => 'Invalid checkout link params',
                'errors' => ['customer' => ['email' => ['must be a valid email']]],
            ], 422),
        ]);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('client.credits.checkout.store'), [
                'package' => 'credits_10',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Não foi possível gerar o link de pagamento agora. Tente novamente em instantes.');

        $payment = Payment::query()->sole();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('provider_error', $payment->failure_reason);
        $this->assertNull($payment->checkout_url);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
    }

    public function test_approved_webhook_confirms_payment_and_credits_wallet_once(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 1_000,
            'paid_amount' => 1_000,
            'capture_method' => 'pix',
        ]);

        $this->sendWebhook($payment)->assertOk()->assertJson(['received' => true]);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame(1_000, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertSame(1, PaymentWebhookEvent::query()
            ->where('status', PaymentEventStatus::Processed->value)
            ->count());
        $this->assertTrue(AuditLog::query()->where('action', 'payment.confirmed')->exists());
    }

    public function test_duplicate_webhook_does_not_duplicate_credits(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 1_000,
            'paid_amount' => 1_000,
        ]);

        $this->sendWebhook($payment)->assertOk();
        $this->sendWebhook($payment)
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => true]);

        $this->assertSame(1_000, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
    }

    public function test_webhook_is_rejected_when_payment_check_does_not_confirm(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => false,
            'amount' => 1_000,
        ]);

        $this->sendWebhook($payment)->assertStatus(400)->assertJson(['received' => false]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(PaymentWebhookEvent::query()
            ->where('failure_reason', 'not_paid_confirmed')
            ->exists());
    }

    public function test_webhook_is_rejected_when_provider_amount_differs_from_the_order(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 500,
            'paid_amount' => 500,
        ]);

        $this->sendWebhook($payment)->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(PaymentWebhookEvent::query()
            ->where('failure_reason', 'amount_mismatch')
            ->exists());
    }

    public function test_webhook_without_references_is_rejected_even_when_amount_matches(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->sendWebhook($payment, [
            'transaction_nsu' => null,
            'invoice_slug' => null,
        ])->assertStatus(400);

        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::CHECK_URL);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
    }

    public function test_webhook_for_unknown_order_is_recorded_without_credits(): void
    {
        $this->postJson(route('webhooks.infinitepay'), [
            'order_nsu' => 'recharge_inexistente',
            'transaction_nsu' => 'txn-orfa',
            'invoice_slug' => 'slug-orfa',
            'amount' => 1_000,
        ])->assertStatus(400)->assertJson(['received' => false, 'reason' => 'unknown_order']);

        $this->assertSame(0, Payment::query()->count());
        $this->assertTrue(PaymentWebhookEvent::query()
            ->whereNull('payment_id')
            ->where('failure_reason', 'unknown_order')
            ->exists());
    }

    public function test_invalid_webhook_body_is_rejected(): void
    {
        $this->postJson(route('webhooks.infinitepay'), [
            'order_nsu' => 'recharge_sem_valor',
        ])->assertStatus(400)->assertJson(['received' => false]);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_canceled_webhook_marks_payment_failed_without_credits(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->sendWebhook($payment, ['event' => 'canceled', 'status' => 'canceled'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('provider_canceled', $payment->failure_reason);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::CHECK_URL);
    }

    public function test_refund_after_confirmation_is_recorded_without_removing_credits(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 1_000,
            'paid_amount' => 1_000,
        ]);

        $this->sendWebhook($payment)->assertOk();
        $this->sendWebhook($payment, ['event' => 'refunded'])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1_000, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(AuditLog::query()
            ->where('action', 'payment.refunded')
            ->where('metadata->manual_review_required', true)
            ->exists());
    }

    public function test_browser_return_does_not_credit_without_provider_confirmation(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => false,
            'amount' => 1_000,
        ]);

        $this->actingAs($client)
            ->get(route('client.credits.checkout.return', [
                'order_nsu' => $payment->external_reference,
                'transaction_nsu' => 'txn-return',
                'slug' => 'slug-abc123',
            ]))
            ->assertRedirect(route('client.credits.payments.show', $payment));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::CHECK_URL);
    }

    public function test_browser_return_credits_after_provider_confirmation(): void
    {
        $client = User::factory()->create();
        $payment = $this->startCheckout($client);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 1_000,
            'paid_amount' => 1_000,
        ]);

        $this->actingAs($client)
            ->get(route('client.credits.checkout.return', [
                'order_nsu' => $payment->external_reference,
                'transaction_nsu' => 'txn-return',
                'slug' => 'slug-abc123',
            ]))
            ->assertRedirect(route('client.credits.payments.show', $payment))
            ->assertSessionHas('success');

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(1_000, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
    }

    public function test_client_cannot_reconcile_another_clients_order(): void
    {
        $owner = User::factory()->create();
        $payment = $this->startCheckout($owner);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('client.credits.checkout.return', [
                'order_nsu' => $payment->external_reference,
            ]))
            ->assertRedirect(route('client.credits.index'));

        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::CHECK_URL);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, (int) ($owner->fresh()->wallet?->balance_cents ?? 0));
    }

    public function test_admin_configured_catalog_defines_the_checkout_packages_and_amounts(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => CreditPackages::SETTING_KEY],
            [
                'value' => json_encode([
                    ['credits' => 12, 'label' => 'Pacote da casa'],
                    ['credits' => 40, 'label' => null],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );

        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('Pacote da casa')
            ->assertSee('R$ 12,00')
            ->assertSee('40 créditos')
            ->assertDontSee('100 créditos');

        $payment = $this->startCheckout($client, 'credits_12');

        $this->assertSame('credits_12', $payment->package_key);
        $this->assertSame(12, $payment->credits);
        $this->assertSame(1_200, $payment->amount_cents);
    }

    public function test_credits_page_lists_checkout_packages_when_provider_is_configured(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('10 créditos')
            ->assertSee('R$ 10,00')
            ->assertSee('100 créditos')
            ->assertSee(route('client.credits.checkout.store'), false);
    }
}
