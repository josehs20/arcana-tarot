<?php

namespace App\Support\Backup;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Restaura um dump `.sql`/`.sql.gz` por cima do banco atual.
 *
 * O fluxo é destrutivo por definição: todas as tabelas do banco corrente são
 * apagadas e recriadas a partir do arquivo. Quem chama precisa ter gravado um
 * backup antes (o painel faz isso automaticamente) — este serviço não tenta
 * desfazer nada se a importação falhar no meio, porque DDL no MySQL não volta
 * atrás com rollback.
 *
 * A execução usa apenas a conexão PDO, comando a comando: nada de
 * `mysql`/`sqlite3` no PATH nem de `exec()`, igual ao dump do `arcana:backup`.
 *
 * Antes de apagar qualquer coisa o cabeçalho do dump é conferido: um dump
 * gerado no SQLite não é aplicado em MySQL (e vice-versa).
 */
final class DatabaseImporter
{
    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    public function __construct(private readonly SqlScriptReader $reader) {}

    public function import(string $path, ?string $connectionName = null): DatabaseImportResult
    {
        $startedAt = microtime(true);

        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        $this->assertCompatible($path, $connectionName);

        $dropped = $this->dropTables($connection, $driver);
        $statements = 0;

        try {
            foreach ($this->reader->statements($path, $this->usesBackslashEscapes($driver)) as $statement) {
                try {
                    $connection->statement($statement);
                } catch (QueryException $exception) {
                    /*
                     * O comando em si não entra na mensagem: ele pode conter
                     * mensagem de chat ou dado financeiro. O detalhe fica na
                     * exceção anterior e no log da aplicação.
                     */
                    throw new RuntimeException(
                        sprintf('Falha no comando %d da importação.', $statements + 1),
                        0,
                        $exception,
                    );
                }

                $statements++;
            }
        } finally {
            $this->restoreForeignKeyChecks($connection, $driver);
        }

        $tables = $this->tableNames($connection, $driver);

        if ($tables === []) {
            throw new RuntimeException('O arquivo não recriou nenhuma tabela; o banco atual ficou vazio.');
        }

        return new DatabaseImportResult(
            driver: $driver,
            dropped: count($dropped),
            statements: $statements,
            tables: count($tables),
            bytes: (int) filesize($path),
            seconds: microtime(true) - $startedAt,
        );
    }

    /**
     * Apaga as tabelas do banco atual, deixando o banco e o usuário intactos.
     *
     * Views não entram na lista: o dump gerado pela aplicação não as inclui e
     * apagar uma view que o dump não recria seria perda silenciosa.
     *
     * @return list<string>
     */
    private function dropTables(ConnectionInterface $connection, string $driver): array
    {
        $tables = $this->tableNames($connection, $driver);

        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA foreign_keys = OFF');
            /*
             * Dentro de uma transação o SQLite ignora o PRAGMA acima. Nesse
             * caso a ordem filhos-antes-de-pais evita a violação de FK que o
             * `DROP TABLE` provoca ao apagar as linhas da tabela.
             */
            $tables = $this->sqliteDropOrder($connection, $tables);
        } else {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
        }

        $grammar = $connection->getQueryGrammar();

        foreach ($tables as $table) {
            $connection->statement('DROP TABLE IF EXISTS '.$grammar->wrapTable($table));
        }

        return $tables;
    }

    /**
     * Ordem de remoção com os filhos antes dos pais, a partir das FKs
     * declaradas no próprio schema.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sqliteDropOrder(ConnectionInterface $connection, array $tables): array
    {
        $grammar = $connection->getQueryGrammar();
        $parents = [];

        foreach ($tables as $table) {
            $rows = $connection->select('PRAGMA foreign_key_list('.$grammar->wrapTable($table).')');

            $parents[$table] = array_values(array_unique(array_filter(
                array_map(static fn (object $row): string => (string) $row->table, $rows),
                static fn (string $parent): bool => in_array($parent, $tables, true),
            )));
        }

        $remaining = $tables;
        $order = [];

        while ($remaining !== []) {
            $dropNow = [];

            foreach ($remaining as $table) {
                $referenced = false;

                foreach ($remaining as $other) {
                    if ($other !== $table && in_array($table, $parents[$other] ?? [], true)) {
                        $referenced = true;

                        break;
                    }
                }

                if (! $referenced) {
                    $dropNow[] = $table;
                }
            }

            if ($dropNow === []) {
                /*
                 * Ciclo de FKs (nenhuma tabela pode ir primeiro): remove o que
                 * sobrou na ordem em que veio; com as FKs desligadas fora de
                 * transação isso continua funcionando.
                 */
                $order = array_merge($order, $remaining);

                break;
            }

            $order = array_merge($order, $dropNow);
            $remaining = array_values(array_diff($remaining, $dropNow));
        }

        return $order;
    }

    /**
     * @return list<string>
     */
    private function tableNames(ConnectionInterface $connection, string $driver): array
    {
        $rows = $driver === 'sqlite'
            ? $connection->select(
                "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name",
            )
            : $connection->select(
                'select table_name as name from information_schema.tables'
                .' where table_schema = database() and table_type = ? order by table_name',
                ['BASE TABLE'],
            );

        return array_values(array_map(
            static fn (object $row): string => (string) $row->name,
            $rows,
        ));
    }

    private function restoreForeignKeyChecks(ConnectionInterface $connection, string $driver): void
    {
        try {
            $connection->statement($driver === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) {
            /*
             * Conexão perdida no meio da importação: a sessão do banco morre
             * com ela, então o ajuste abaixo não tem mais efeito nenhum.
             */
        }
    }

    private function usesBackslashEscapes(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    /**
     * Um dump da própria aplicação sempre começa com o driver no cabeçalho.
     * Arquivos de outras origens (sem cabeçalho) seguem para a execução.
     *
     * É público para o painel recusar um arquivo incompatível **antes** de
     * gravar o backup de segurança e apagar as tabelas.
     */
    public function assertCompatible(string $path, ?string $connectionName = null): void
    {
        $driver = DB::connection($connectionName)->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new RuntimeException("Driver de banco sem suporte a importação: {$driver}.");
        }

        $header = $this->reader->header($path);

        if (preg_match('/^--\s*Driver:\s*([a-z]+)/mi', $header, $matches) !== 1) {
            return;
        }

        $dumpDriver = strtolower($matches[1]);
        $current = $driver === 'mariadb' ? 'mysql' : $driver;
        $dump = $dumpDriver === 'mariadb' ? 'mysql' : $dumpDriver;

        if ($current !== $dump) {
            throw new RuntimeException(
                "O arquivo é um dump de {$dumpDriver} e o banco atual é {$driver}. Importação cancelada.",
            );
        }
    }
}
