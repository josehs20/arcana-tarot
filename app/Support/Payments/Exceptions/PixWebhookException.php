<?php

namespace App\Support\Payments\Exceptions;

use RuntimeException;
use Throwable;

class PixWebhookException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
