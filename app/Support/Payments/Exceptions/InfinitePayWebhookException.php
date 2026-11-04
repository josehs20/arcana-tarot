<?php

namespace App\Support\Payments\Exceptions;

use RuntimeException;

/**
 * Notificação recebida com corpo inválido ou sem os campos obrigatórios.
 *
 * Carrega o status HTTP devolvido ao provedor para sinalizar que a entrega não
 * foi aceita e deve ser reenviada.
 */
class InfinitePayWebhookException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
