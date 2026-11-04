<?php

namespace App\Support\Payments;

use App\Enums\PaymentWebhookOutcome;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;

final readonly class PaymentWebhookResult
{
    public function __construct(
        public PaymentWebhookOutcome $outcome,
        public ?Payment $payment = null,
        public ?PaymentWebhookEvent $event = null,
        public ?string $reason = null,
    ) {}
}
