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
            'provider_payment_id' => fake()->uuid(),
            'amount_cents' => 10000,
            'credits' => 100,
            'credit_unit_price_cents' => 100,
            'status' => PaymentStatus::Pending->value,
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
