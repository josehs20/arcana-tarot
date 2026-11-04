<?php

namespace App\Support\Payments\InfinitePay\Data;

/**
 * Resultado da consulta `payment_check`: a única fonte aceita para liberar
 * créditos. A notificação do webhook nunca é suficiente sozinha.
 */
final readonly class PaymentCheck
{
    public function __construct(
        public bool $success,
        public bool $paid,
        public ?int $amountCents,
        public ?int $paidAmountCents = null,
        public ?string $captureMethod = null,
        public int $installments = 1,
    ) {}

    public function confirmed(): bool
    {
        return $this->success && $this->paid;
    }
}
