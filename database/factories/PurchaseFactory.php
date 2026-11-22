<?php

namespace Database\Factories;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'product_title' => 'Pacote 10 créditos',
            'amount_cents' => 1_000,
            'credits_amount' => 10,
            'credit_unit_price_cents' => 100,
            'status' => PurchaseStatus::Pending,
            'provider' => 'infinitepay',
            'external_reference' => 'product_'.Str::ulid(),
            'idempotency_key' => null,
            'checkout_url' => null,
            'invoice_slug' => null,
            'transaction_nsu' => null,
            'failure_reason' => null,
            'paid_at' => null,
            'refunded_at' => null,
        ];
    }

    /**
     * Congela no pedido os dados do produto informado, como o checkout real.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->getKey(),
            'product_title' => $product->title,
            'amount_cents' => $product->price_cents,
            'credits_amount' => $product->credits_amount,
            'credit_unit_price_cents' => $product->credits_amount > 0
                ? intdiv($product->price_cents, $product->credits_amount)
                : null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseStatus::Cancelled,
            'failure_reason' => 'provider_canceled',
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseStatus::Refunded,
            'paid_at' => now()->subDay(),
            'refunded_at' => now(),
        ]);
    }
}
