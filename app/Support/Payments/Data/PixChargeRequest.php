<?php

namespace App\Support\Payments\Data;

use DateTimeInterface;

final readonly class PixChargeRequest
{
    public function __construct(
        public string $externalReference,
        public int $amountCents,
        public string $description,
        public DateTimeInterface $expiresAt,
    ) {}
}
