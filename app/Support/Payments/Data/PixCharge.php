<?php

namespace App\Support\Payments\Data;

use App\Enums\PixEventStatus;
use DateTimeInterface;

final readonly class PixCharge
{
    public function __construct(
        public string $providerPaymentId,
        public string $copyPaste,
        public PixEventStatus $status,
        public string $rawStatus,
        public DateTimeInterface $expiresAt,
    ) {}
}
