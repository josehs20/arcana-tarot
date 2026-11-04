<?php

namespace App\Support\Legacy;

/**
 * Mapa entre o dump legado e o modelo do Arcana, produzido pela inspeção do
 * `CREATE TABLE`.
 *
 * O plano é apenas descritivo: nenhum nome de tabela/coluna é interpolado em
 * SQL. O importador sempre lê o dump pelo nome exato declarado aqui.
 */
final class LegacyImportPlan
{
    /**
     * @param  array<int, string>  $blockingIssues  impedem a importação
     * @param  array<int, string>  $notes  avisos informativos
     * @param  array<int, array{table: string, reason: string}>  $rejectedCandidates
     */
    public function __construct(
        public readonly ?LegacyDumpTable $userTable = null,
        public readonly ?string $emailColumn = null,
        public readonly ?string $passwordColumn = null,
        public readonly ?string $nameColumn = null,
        public readonly ?string $phoneColumn = null,
        public readonly ?string $legacyKeyColumn = null,
        public readonly ?LegacyDumpTable $creditsTable = null,
        public readonly ?string $creditsUserColumn = null,
        public readonly ?string $creditsBalanceColumn = null,
        public readonly array $blockingIssues = [],
        public readonly array $notes = [],
        public readonly array $rejectedCandidates = [],
    ) {}

    public function isReady(): bool
    {
        return $this->blockingIssues === []
            && $this->userTable !== null
            && $this->emailColumn !== null
            && $this->passwordColumn !== null;
    }

    public function hasCredits(): bool
    {
        return $this->creditsTable !== null
            && $this->creditsUserColumn !== null
            && $this->creditsBalanceColumn !== null;
    }

    /**
     * Resumo textual da origem mapeada, usado no relatório e na auditoria.
     */
    public function sourceLabel(): string
    {
        if ($this->userTable === null) {
            return 'nenhuma tabela de usuários identificada';
        }

        return $this->userTable->name.' (e-mail: '.($this->emailColumn ?? '—').', senha: '.($this->passwordColumn ?? '—').')';
    }

    public function creditsLabel(): string
    {
        if (! $this->hasCredits()) {
            return 'nenhum campo de saldo identificado';
        }

        return $this->creditsTable->name.'.'.$this->creditsBalanceColumn
            .' (vínculo: '.$this->creditsUserColumn.')';
    }
}
