<?php

namespace App\Support;

use DomainException;

/**
 * Tentativa de débito maior que o saldo disponível.
 *
 * A exceção carrega os valores solicitado/disponível para que a camada que
 * chamou possa exibir uma mensagem útil sem consultar novamente a carteira.
 */
final class InsufficientCreditBalanceException extends DomainException
{
    public function __construct(
        public readonly int $requestedCents,
        public readonly int $availableCents,
    ) {
        parent::__construct(
            'Saldo insuficiente: solicitado '.Money::format($requestedCents)
            .', disponível '.Money::format($availableCents).'.',
        );
    }
}
