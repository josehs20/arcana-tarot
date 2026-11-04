<?php

namespace App\Support\Payments\InfinitePay\Data;

/**
 * Identificadores exigidos pelo endpoint `payment_check` da InfinitePay.
 */
final readonly class PaymentCheckRequest
{
    public function __construct(
        public string $orderNsu,
        public string $transactionNsu,
        public string $invoiceSlug,
    ) {}
}
