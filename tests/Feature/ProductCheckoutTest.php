<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Criação do checkout de produtos.
 *
 * O valor e a quantidade de créditos são do servidor; o navegador envia apenas
 * a operação idempotente.
 */
class ProductCheckoutTest extends TestCase
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

    private function fakeCheckoutLink(): void
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'url' => 'https://checkout.infinitepay.io/prod123',
                'invoice_slug' => 'slug-prod123',
            ]),
        ]);
    }

    private function startCheckout(User $client, Product $product, ?string $operationId = null): void
    {
        $this->actingAs($client)
            ->post(route('client.products.checkout.store', $product), [
                'operation_id' => $operationId ?? (string) Str::uuid(),
            ])
            ->assertRedirect('https://checkout.infinitepay.io/prod123');
    }

    public function test_guest_is_redirected_to_login_before_buying(): void
    {
        $product = Product::factory()->create();

        $this->post(route('client.products.checkout.store', $product), [
            'operation_id' => (string) Str::uuid(),
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Purchase::query()->count());
        Http::assertNothingSent();
    }

    public function test_checkout_freezes_the_server_side_product_snapshot(): void
    {
        $client = User::factory()->create();
        $product = Product::factory()->create([
            'title' => 'Leitura completa',
            'credits_amount' => 30,
            'price_cents' => 7_900,
        ]);

        $this->fakeCheckoutLink();
        $this->startCheckout($client, $product);

        $purchase = Purchase::query()->sole();

        $this->assertSame($client->getKey(), $purchase->user_id);
        $this->assertSame($product->getKey(), $purchase->product_id);
        $this->assertSame('Leitura completa', $purchase->product_title);
        $this->assertSame(7_900, $purchase->amount_cents);
        $this->assertSame(30, $purchase->credits_amount);
        $this->assertSame(263, $purchase->credit_unit_price_cents);
        $this->assertSame(PurchaseStatus::Pending, $purchase->status);
        $this->assertSame('https://checkout.infinitepay.io/prod123', $purchase->checkout_url);
        $this->assertSame('slug-prod123', $purchase->invoice_slug);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::LINKS_URL
            && $request['items'][0]['price'] === 7_900
            && $request['items'][0]['quantity'] === 1
            && $request['redirect_url'] === route('client.products.checkout.return'));
    }

    public function test_repeated_checkout_with_same_operation_reuses_the_purchase(): void
    {
        $client = User::factory()->create();
        $product = Product::factory()->create([
            'credits_amount' => 10,
            'price_cents' => 2_900,
        ]);
        $operationId = (string) Str::uuid();

        $this->fakeCheckoutLink();
        $this->startCheckout($client, $product, $operationId);
        $this->startCheckout($client, $product, $operationId);

        $this->assertSame(1, Purchase::query()->count());
        Http::assertSentCount(1);
    }

    public function test_home_shows_only_active_products_and_a_guest_call_to_action(): void
    {
        Product::factory()->create([
            'title' => 'Pacote visível na home',
            'price_cents' => 2_900,
            'is_active' => true,
        ]);
        Product::factory()->inactive()->create(['title' => 'Pacote oculto na home']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Pacote visível na home')
            ->assertSee('R$ 29,00')
            ->assertSee('Criar conta e comprar')
            ->assertDontSee('Pacote oculto na home');
    }

    public function test_inactive_product_cannot_be_checked_out(): void
    {
        $client = User::factory()->create();
        $product = Product::factory()->inactive()->create();

        $this->actingAs($client)
            ->post(route('client.products.checkout.store', $product), [
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, Purchase::query()->count());
        Http::assertNothingSent();
    }

    public function test_provider_error_cancels_the_purchase_without_credits(): void
    {
        Http::fake([
            self::LINKS_URL => Http::response([
                'success' => false,
                'message' => 'Invalid checkout link params',
            ], 422),
        ]);

        $client = User::factory()->create();
        $product = Product::factory()->create(['price_cents' => 2_900]);

        $this->actingAs($client)
            ->post(route('client.products.checkout.store', $product), [
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Não foi possível gerar o link de pagamento agora. Tente novamente em instantes.');

        $purchase = Purchase::query()->sole();

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->status);
        $this->assertSame('provider_error', $purchase->failure_reason);
        $this->assertNull($purchase->checkout_url);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
    }

    public function test_browser_return_does_not_credit_without_provider_confirmation(): void
    {
        $client = User::factory()->create();
        $product = Product::factory()->create([
            'credits_amount' => 10,
            'price_cents' => 2_900,
        ]);

        $this->fakeCheckoutLink();
        $this->startCheckout($client, $product);

        $purchase = Purchase::query()->sole();

        Http::fake([
            self::CHECK_URL => Http::response([
                'success' => true,
                'paid' => false,
                'amount' => 2_900,
            ]),
        ]);

        $this->actingAs($client)
            ->get(route('client.products.checkout.return', [
                'order_nsu' => $purchase->external_reference,
                'transaction_nsu' => 'txn-product',
                'slug' => 'slug-prod123',
            ]))
            ->assertRedirect(route('client.purchases.index'));

        $this->assertSame(PurchaseStatus::Pending, $purchase->fresh()->status);
        $this->assertSame(0, (int) ($client->fresh()->wallet?->balance_cents ?? 0));
    }
}
