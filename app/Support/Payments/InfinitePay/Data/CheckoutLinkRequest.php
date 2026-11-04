<?php

namespace App\Support\Payments\InfinitePay\Data;

use DateTimeInterface;

/**
 * Dados necessários para gerar um link de checkout na InfinitePay.
 *
 * O valor é sempre definido no servidor, em centavos, a partir do preço do
 * crédito e do pacote escolhido pelo cliente.
 */
final readonly class CheckoutLinkRequest
{
    public function __construct(
        public string $orderNsu,
        public int $amountCents,
        public string $description,
        public ?string $redirectUrl = null,
        public ?string $webhookUrl = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?DateTimeInterface $validUntil = null,
    ) {}
}
