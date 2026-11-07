<?php

namespace App\Support\Legacy;

use Generator;

/**
 * Origem dos dados legados usada pela importação.
 *
 * O legado pode ser exportado como dump SQL (`mysqldump`/phpMyAdmin), como CSV
 * de um único arquivo ou como um arquivo por tabela. O restante da importação
 * depende apenas desta interface, então o formato e a quantidade de arquivos não
 * mudam as regras de mapeamento, senha, crédito ou auditoria.
 *
 * Nenhuma implementação executa SQL: o conteúdo é apenas interpretado.
 */
interface LegacyDumpSource
{
    /**
     * Arquivos que compõem a origem, com o tamanho de cada um.
     *
     * @return array<int, array{path: string, size: int}>
     */
    public function files(): array;

    /**
     * Tamanho total da origem em bytes.
     */
    public function size(): int;

    /**
     * Como a origem é chamada nas mensagens ("dump SQL", "arquivo CSV").
     */
    public function sourceLabel(): string;

    /**
     * Problemas de integridade do fim de cada arquivo: exportação cortada por
     * erro do servidor (`marker`) ou interrompida no meio de um comando
     * (`unterminated`). Arquivos íntegros não geram entrada.
     *
     * @return array<int, array{path: string, marker: string|null, unterminated: bool}>
     */
    public function integrityIssues(): array;

    /**
     * Tabelas encontradas na origem.
     *
     * @return array<string, LegacyDumpTable> índice em minúsculas para busca
     */
    public function tables(): array;

    /**
     * Percorre os registros de uma tabela da origem.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(LegacyDumpTable $table): Generator;
}
