<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'pix_sandbox',
            'provider_payment_id' => 'sandbox_'.fake()->uuid(),
            'external_reference' => 'recharge_'.fake()->uuid(),
            'amount_cents' => 10000,
            'credits' => 100,
            'credit_unit_price_cents' => 100,
            'status' => PaymentStatus::Pending->value,
            'raw_status' => 'waiting_payment',
            'idempotency_key' => fake()->uuid(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Confirmed->value,
            'raw_status' => 'approved',
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed->value,
            'raw_status' => 'declined',
            'failure_reason' => 'provider_declined',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Expired->value,
            'raw_status' => 'expired',
            'failure_reason' => 'provider_expired',
        ]);
    }
}
