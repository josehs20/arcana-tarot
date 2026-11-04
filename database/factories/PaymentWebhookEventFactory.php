<?php

namespace Database\Factories;

use App\Enums\PaymentEventStatus;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
class PaymentWebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'provider' => 'pix_sandbox',
            'provider_event_id' => 'evt_'.Str::uuid(),
            'event_type' => 'payment.updated',
            'provider_payment_id' => 'sandbox_'.Str::uuid(),
            'status' => PaymentEventStatus::Processed->value,
            'payload_hash' => hash('sha256', Str::random(32)),
            'amount_cents' => 10000,
            'occurred_at' => now(),
            'processed_at' => now(),
        ];
    }
}
