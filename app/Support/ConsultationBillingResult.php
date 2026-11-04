<?php

namespace App\Support;

/**
 * Resultado de uma execução de cobrança por tempo.
 *
 * `dueMinutes` é o total de minutos iniciados desde `started_at` até o fim
 * efetivo da consulta (ou o instante da cobrança, se ainda ativa).
 * `billedMinutes` é o cursor já persistido após a execução.
 */
final readonly class ConsultationBillingResult
{
    public function __construct(
        public int $dueMinutes,
        public int $billedMinutes,
        public int $chargedMinutes,
        public int $chargedCents,
        public ?int $unpaidMinute,
        public ?int $balanceAfterCents,
    ) {}

    public function isFullyBilled(): bool
    {
        return $this->unpaidMinute === null;
    }

    public function hasUnpaidMinute(): bool
    {
        return $this->unpaidMinute !== null;
    }
}
