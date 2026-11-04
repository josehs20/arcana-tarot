<?php

namespace App\Support\Payments\Data;

use App\Enums\PixEventStatus;
use DateTimeInterface;

final readonly class PixWebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $providerPaymentId,
        public PixEventStatus $status,
        public int $amountCents,
        public string $payloadHash,
        public ?DateTimeInterface $occurredAt = null,
    ) {}
}
