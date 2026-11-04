<?php

namespace App\Support\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falha de comunicação ou de contrato com a API da InfinitePay.
 *
 * É tratada como erro transitório: nada é creditado e a operação pode ser
 * repetida com segurança.
 */
class InfinitePayException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
