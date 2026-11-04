<?php

namespace App\Support\Payments\InfinitePay\Data;

/**
 * Link de checkout devolvido pela InfinitePay.
 */
final readonly class CheckoutLink
{
    public function __construct(
        public string $orderNsu,
        public string $url,
        public ?string $invoiceSlug = null,
        public string $rawStatus = 'waiting_payment',
    ) {}
}
