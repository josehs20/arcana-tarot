<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Histórico "Minhas compras" do cliente.
 */
class ClientPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_sees_only_their_own_purchases_and_paid_totals(): void
    {
        $client = User::factory()->create();
        $other = User::factory()->create();

        $paidProduct = Product::factory()->create(['title' => 'Leitura completa']);
        $pendingProduct = Product::factory()->create(['title' => 'Consulta rápida']);
        $otherProduct = Product::factory()->create(['title' => 'Pacote de outra pessoa']);

        Purchase::factory()->for($client)->forProduct($paidProduct)->paid()->create([
            'amount_cents' => 7_900,
            'credits_amount' => 30,
        ]);
        Purchase::factory()->for($client)->forProduct($pendingProduct)->create([
            'amount_cents' => 2_900,
            'credits_amount' => 10,
        ]);
        Purchase::factory()->for($other)->forProduct($otherProduct)->paid()->create();

        $this->actingAs($client)
            ->get(route('client.purchases.index'))
            ->assertOk()
            ->assertSee('Leitura completa')
            ->assertSee('Consulta rápida')
            ->assertDontSee('Pacote de outra pessoa')
            ->assertSee('R$ 79,00')
            ->assertSee('Aguardando pagamento');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('client.purchases.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_cannot_open_the_client_purchase_history(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('client.purchases.index'))
            ->assertForbidden();
    }
}
