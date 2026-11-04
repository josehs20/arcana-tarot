<?php

namespace App\Support\Payments\InfinitePay\Data;

use App\Enums\InfinitePayEvent;
use DateTimeInterface;

/**
 * Notificação de webhook já normalizada e identificada.
 *
 * `payloadHash` guarda a impressão digital do corpo recebido para auditoria e
 * `eventId` é a chave de idempotência usada em `payment_webhook_events`.
 */
final readonly class WebhookNotification
{
    public function __construct(
        public string $eventId,
        public InfinitePayEvent $event,
        public string $orderNsu,
        public int $amountCents,
        public string $payloadHash,
        public ?string $transactionNsu = null,
        public ?string $invoiceSlug = null,
        public ?int $paidAmountCents = null,
        public ?string $captureMethod = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {}
}
