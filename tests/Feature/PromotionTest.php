<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Setting;
use App\Models\User;
use App\Support\PromotionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Campanhas "Compre X créditos e ganhe Y".
 *
 * Cobre o cadastro no painel, a exibição pública da campanha vigente e a
 * concessão do bônus dentro da confirmação do pagamento — incluindo a
 * idempotência que impede webhooks repetidos de duplicarem saldo.
 */
class PromotionTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function promotion(array $attributes = []): Promotion
    {
        return Promotion::factory()->create([
            'title' => 'Semana do tarô',
            'description' => 'Bônus válido para recargas de 10 créditos.',
            'buy_credits' => 10,
            'bonus_credits' => 2,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Semana do tarô',
            'description' => 'Bônus válido para recargas de 10 créditos.',
            'buy_credits' => 10,
            'bonus_credits' => 2,
            'is_active' => 1,
            'starts_at' => null,
            'ends_at' => null,
            ...$overrides,
        ];
    }

    public function test_guest_is_redirected_to_login_on_every_promotion_route(): void
    {
        $promotion = $this->promotion();

        $requests = [
            ['get', route('admin.promotions.index')],
            ['get', route('admin.promotions.create')],
            ['post', route('admin.promotions.store')],
            ['get', route('admin.promotions.edit', $promotion)],
            ['patch', route('admin.promotions.update', $promotion)],
            ['patch', route('admin.promotions.status', $promotion)],
            ['delete', route('admin.promotions.destroy', $promotion)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->assertSame(1, Promotion::query()->count());
    }

    public function test_non_admin_and_inactive_admin_cannot_manage_promotions(): void
    {
        $promotion = $this->promotion();

        $users = [
            User::factory()->create(),
            User::factory()->attendant()->create(),
            User::factory()->admin()->create(['is_active' => false]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('admin.promotions.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.promotions.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->patch(route('admin.promotions.update', $promotion), $this->payload())->assertForbidden();
            $this->actingAs($user)->patch(route('admin.promotions.status', $promotion), ['is_active' => 0])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.promotions.destroy', $promotion))->assertForbidden();
        }

        $this->assertTrue($promotion->fresh()->is_active);
        $this->assertSame(1, Promotion::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admin_creates_promotion_and_the_action_is_audited(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.promotions.store'), $this->payload())
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $promotion = Promotion::query()->sole();

        $this->assertSame('Semana do tarô', $promotion->title);
        $this->assertSame(10, $promotion->buy_credits);
        $this->assertSame(2, $promotion->bonus_credits);
        $this->assertTrue($promotion->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'promotion.created')->exists());
    }

    public function test_promotion_requires_a_valid_window_and_positive_credits(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.promotions.store'), $this->payload([
                'buy_credits' => 0,
                'bonus_credits' => 0,
                'starts_at' => '2026-12-10',
                'ends_at' => '2026-12-01',
            ]))
            ->assertSessionHasErrors(['buy_credits', 'bonus_credits', 'ends_at']);

        $this->assertSame(0, Promotion::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admin_updates_and_toggles_promotion_status(): void
    {
        $admin = $this->admin();
        $promotion = $this->promotion();

        $this->actingAs($admin)
            ->patch(route('admin.promotions.update', $promotion), $this->payload([
                'title' => 'Semana do tarô II',
                'bonus_credits' => 4,
                'is_active' => 0,
            ]))
            ->assertRedirect(route('admin.promotions.edit', $promotion));

        $promotion->refresh();

        $this->assertSame('Semana do tarô II', $promotion->title);
        $this->assertSame(4, $promotion->bonus_credits);
        $this->assertFalse($promotion->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.promotions.status', $promotion), ['is_active' => 1])
            ->assertRedirect();

        $this->assertTrue($promotion->fresh()->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'promotion.updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'promotion.activated')->exists());
    }

    public function test_admin_removes_promotion_without_erasing_payment_history(): void
    {
        $admin = $this->admin();
        $promotion = $this->promotion();

        $payment = Payment::factory()->confirmed()->create([
            'promotion_id' => $promotion->id,
            'promotion_bonus_credits' => 2,
            'promotion_bonus_cents' => 200,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.promotions.destroy', $promotion))
            ->assertRedirect(route('admin.promotions.index'));

        $this->assertSoftDeleted($promotion);
        $this->assertTrue(AuditLog::query()->where('action', 'promotion.deleted')->exists());
        $this->assertSame(2, $payment->fresh()->promotion_bonus_credits);
        $this->assertSame(200, $payment->fresh()->promotion_bonus_cents);
    }

    public function test_active_scope_returns_only_running_promotions(): void
    {
        $running = $this->promotion(['title' => 'Vigente']);
        $this->promotion(['title' => 'Inativa', 'is_active' => false]);
        $this->promotion(['title' => 'Expirada', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
        $this->promotion(['title' => 'Agendada', 'starts_at' => now()->addDay()]);

        $active = app(PromotionManager::class)->active();

        $this->assertSame([$running->id], $active->pluck('id')->all());
    }

    public function test_home_lists_running_promotions_and_hides_the_rest(): void
    {
        $this->promotion();

        $this->promotion([
            'title' => 'Promoção antiga',
            'bonus_credits' => 9,
            'is_active' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Compre créditos e ganhe bônus')
            ->assertSee('Semana do tarô')
            ->assertSee('Compre 10 créditos e ganhe +2')
            ->assertSee('Criar conta e aproveitar')
            ->assertDontSee('Promoção antiga');
    }

    public function test_credits_page_highlights_active_promotions(): void
    {
        $this->promotion();

        $this->actingAs(User::factory()->create())
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('Promoções ativas')
            ->assertSee('Compre 10 créditos e ganhe +2')
            ->assertSee('Este pacote rende +2 créditos de bônus');
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

    private function fakePaymentCheck(): void
    {
        Http::fake([
            self::CHECK_URL => Http::response([
                'success' => true,
                'paid' => true,
                'amount' => 1_000,
                'paid_amount' => 1_000,
            ]),
        ]);
    }

    private function startCheckout(User $client): Payment
    {
        $this->fakeCheckoutLink();

        $this->actingAs($client)
            ->post(route('client.credits.checkout.store'), [
                'package' => 'credits_10',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect('https://checkout.infinitepay.io/abc123');

        return Payment::query()->latest('id')->firstOrFail();
    }

    private function sendWebhook(Payment $payment): TestResponse
    {
        return $this->postJson(route('webhooks.infinitepay'), [
            'order_nsu' => $payment->external_reference,
            'transaction_nsu' => 'txn-'.$payment->getKey(),
            'invoice_slug' => 'slug-abc123',
            'amount' => $payment->amount_cents,
        ]);
    }

    public function test_confirmed_payment_credits_the_promotion_bonus_exactly_once(): void
    {
        $promotion = $this->promotion();
        $client = User::factory()->create();

        $payment = $this->startCheckout($client);

        $this->assertSame($promotion->id, $payment->promotion_id);
        $this->assertSame(0, $payment->promotion_bonus_credits);

        $this->fakePaymentCheck();
        $this->sendWebhook($payment)->assertOk()->assertJson(['received' => true]);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame($promotion->id, $payment->promotion_id);
        $this->assertSame(2, $payment->promotion_bonus_credits);
        $this->assertSame(200, $payment->promotion_bonus_cents);

        // 10 créditos comprados (R$ 10,00) + 2 de bônus (R$ 2,00).
        $this->assertSame(1_200, (int) $client->fresh()->wallet?->balance_cents);

        $bonusKey = PromotionManager::idempotencyKeyFor($payment);

        $this->assertSame(1, CreditLedgerEntry::query()->where('idempotency_key', $bonusKey)->count());
        $this->assertSame(
            'Bônus da promoção: Compre 10 créditos e ganhe +2',
            CreditLedgerEntry::query()->where('idempotency_key', $bonusKey)->sole()->reason,
        );
        $this->assertTrue(AuditLog::query()->where('action', 'payment.promotion_applied')->exists());

        // O webhook repetido é reconhecido e não duplica o bônus.
        $this->sendWebhook($payment)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1_200, (int) $client->fresh()->wallet?->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->where('idempotency_key', $bonusKey)->count());

        $this->actingAs($client)
            ->get(route('client.credits.payments.show', $payment))
            ->assertOk()
            ->assertSee('Bônus da promoção')
            ->assertSee('+2 créditos')
            ->assertSee('Bônus creditado')
            ->assertSee('data-promotion-bonus-modal', false);
    }

    public function test_bonus_is_honored_when_the_promotion_is_deactivated_after_checkout(): void
    {
        $promotion = $this->promotion();
        $client = User::factory()->create();

        $payment = $this->startCheckout($client);

        // O pedido registrou a campanha: inativar depois não retira o bônus.
        $promotion->update(['is_active' => false]);

        $this->fakePaymentCheck();
        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(2, $payment->fresh()->promotion_bonus_credits);
        $this->assertSame(1_200, (int) $client->fresh()->wallet?->balance_cents);
    }

    public function test_payment_without_matching_promotion_credits_only_the_purchase(): void
    {
        $this->promotion(['buy_credits' => 50]);
        $client = User::factory()->create();

        $payment = $this->startCheckout($client);

        $this->assertNull($payment->promotion_id);

        $this->fakePaymentCheck();
        $this->sendWebhook($payment)->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame(0, $payment->promotion_bonus_credits);
        $this->assertSame(0, $payment->promotion_bonus_cents);
        $this->assertSame(1_000, (int) $client->fresh()->wallet?->balance_cents);
        $this->assertFalse(AuditLog::query()->where('action', 'payment.promotion_applied')->exists());
    }
}
