<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\AuditLog;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Confirmação de compras de produtos pelo webhook da InfinitePay.
 *
 * Nenhum crédito é liberado sem `payment_check` aprovado, e notificações
 * repetidas não duplicam saldo nem eventos.
 */
class ProductWebhookTest extends TestCase
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
        ]);

        Http::preventStrayRequests();
    }

    private function startPurchase(User $client, Product $product): Purchase
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'url' => 'https://checkout.infinitepay.io/prod123',
                'invoice_slug' => 'slug-prod123',
            ]),
        ]);

        $this->actingAs($client)
            ->post(route('client.products.checkout.store', $product), [
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect('https://checkout.infinitepay.io/prod123');

        return Purchase::query()->latest('id')->firstOrFail();
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendWebhook(Purchase $purchase, array $overrides = []): TestResponse
    {
        return $this->postJson(route('webhooks.infinitepay'), array_merge([
            'order_nsu' => $purchase->external_reference,
            'transaction_nsu' => 'txn-'.$purchase->getKey(),
            'invoice_slug' => 'slug-prod123',
            'amount' => $purchase->amount_cents,
        ], $overrides));
    }

    private function productAndClient(): array
    {
        $client = User::factory()->create();
        $product = Product::factory()->create([
            'title' => 'Leitura completa',
            'credits_amount' => 30,
            'price_cents' => 7_900,
        ]);

        return [$client, $product];
    }

    public function test_approved_webhook_marks_the_purchase_paid_and_credits_the_wallet_once(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 7_900,
            'paid_amount' => 7_900,
            'capture_method' => 'pix',
        ]);

        $this->sendWebhook($purchase)->assertOk()->assertJson(['received' => true]);

        $purchase->refresh();

        $this->assertSame(PurchaseStatus::Paid, $purchase->status);
        $this->assertNotNull($purchase->paid_at);
        $this->assertSame(7_900, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(AuditLog::query()->where('action', 'purchase.paid')->exists());
        $this->assertDatabaseHas('payment_webhook_events', [
            'purchase_id' => $purchase->getKey(),
            'payment_id' => null,
            'event_type' => 'paid',
        ]);
    }

    public function test_duplicate_webhook_does_not_duplicate_credits_or_events(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 7_900,
            'paid_amount' => 7_900,
        ]);

        $this->sendWebhook($purchase)->assertOk();
        $this->sendWebhook($purchase)
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => true]);

        $this->assertSame(7_900, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
    }

    public function test_amount_mismatch_keeps_the_purchase_pending_without_credits(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 1_000,
            'paid_amount' => 1_000,
        ]);

        $this->sendWebhook($purchase)->assertStatus(400);

        $this->assertSame(PurchaseStatus::Pending, $purchase->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(PaymentWebhookEvent::query()
            ->where('failure_reason', 'amount_mismatch')
            ->exists());
    }

    public function test_canceled_webhook_cancels_the_purchase_without_credits(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->sendWebhook($purchase, ['event' => 'canceled', 'status' => 'canceled'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $purchase->refresh();

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->status);
        $this->assertSame('provider_canceled', $purchase->failure_reason);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::CHECK_URL);
    }

    public function test_refund_after_payment_is_recorded_without_removing_credits(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 7_900,
            'paid_amount' => 7_900,
        ]);

        $this->sendWebhook($purchase)->assertOk();
        $this->sendWebhook($purchase, ['event' => 'refunded', 'status' => 'refunded'])->assertOk();

        $purchase->refresh();

        $this->assertSame(PurchaseStatus::Refunded, $purchase->status);
        $this->assertNotNull($purchase->refunded_at);
        $this->assertSame(7_900, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
        $this->assertTrue(AuditLog::query()
            ->where('action', 'purchase.refunded')
            ->where('metadata->manual_review_required', true)
            ->exists());
    }

    public function test_purchase_keeps_the_snapshot_after_the_product_is_edited(): void
    {
        [$client, $product] = $this->productAndClient();
        $purchase = $this->startPurchase($client, $product);

        $this->fakePaymentCheck([
            'success' => true,
            'paid' => true,
            'amount' => 7_900,
            'paid_amount' => 7_900,
        ]);

        $this->sendWebhook($purchase)->assertOk();

        $product->forceFill([
            'title' => 'Título alterado depois da venda',
            'price_cents' => 19_900,
            'credits_amount' => 99,
        ])->save();

        $purchase->refresh();

        $this->assertSame('Leitura completa', $purchase->product_title);
        $this->assertSame(7_900, $purchase->amount_cents);
        $this->assertSame(30, $purchase->credits_amount);
    }

    public function test_unknown_product_reference_is_recorded_by_the_shared_webhook(): void
    {
        $this->postJson(route('webhooks.infinitepay'), [
            'order_nsu' => 'product_inexistente',
            'transaction_nsu' => 'txn-orfa',
            'invoice_slug' => 'slug-orfa',
            'amount' => 2_900,
        ])->assertStatus(400)->assertJson(['received' => false, 'reason' => 'unknown_order']);

        $this->assertSame(0, Purchase::query()->count());
        $this->assertTrue(PaymentWebhookEvent::query()
            ->whereNull('payment_id')
            ->whereNull('purchase_id')
            ->where('failure_reason', 'unknown_order')
            ->exists());
    }
}
