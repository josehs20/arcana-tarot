<?php

namespace App\Support\Backup;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Gera o dump lógico do banco em SQL comprimido com gzip.
 *
 * O dump é feito pela própria conexão PDO, sem depender do binário
 * `mysqldump`/`sqlite3`: a imagem de produção é `php:8.4-fpm-alpine` sem os
 * clientes de banco, e hospedagem compartilhada pode não ter `exec()` liberado.
 * Os dados são lidos em páginas (com `where` pela chave primária, para não
 * depender de `OFFSET` em tabelas grandes) e gravados em `INSERT` de várias
 * linhas. Valores binários saem em hexadecimal (`0x...`), então o arquivo é
 * texto puro e restaura tanto pelo cliente `mysql` quanto pelo `sqlite3`.
 *
 * Em MySQL/MariaDB a leitura acontece dentro de uma transação `REPEATABLE READ`
 * com snapshot consistente: o backup representa um instante único, mesmo com a
 * aplicação gravando durante a execução. SQLite já garante leitura consistente
 * por transação de leitura.
 *
 * Limitações conhecidas: não inclui *triggers*, *procedures* nem *events* (o
 * projeto não usa nenhum deles); as `CREATE TABLE` vêm do próprio servidor
 * (`SHOW CREATE TABLE`/`sqlite_master`), então índices e colunas geradas são
 * preservados como estão hoje no banco.
 */
class DatabaseDumper
{
    /**
     * Linhas lidas por consulta. Alto o suficiente para o dump terminar em
     * segundos e baixo o suficiente para uma página não estourar memória em
     * tabelas com textos longos (histórico de chat, payloads de webhook).
     */
    private const PAGE_SIZE = 200;

    /**
     * Linhas por `INSERT`. Acima disso o arquivo fica lento de restaurar; abaixo,
     * o dump cresce demais com o cabeçalho de cada comando.
     */
    private const ROWS_PER_INSERT = 100;

    /**
     * Teto do comando em construção; protege contra linhas de vários MB.
     */
    private const MAX_STATEMENT_BYTES = 524_288;

    /**
     * Grava o dump em `$destinationPath` (tipicamente `database.sql.gz`).
     *
     * Em caso de falha o arquivo parcial é removido e a exceção é relançada:
     * backup incompleto nunca deve parecer backup válido.
     */
    public function dump(string $destinationPath, ?string $connectionName = null): DatabaseDumpResult
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Driver de banco sem suporte a backup: {$driver}.");
        }

        $tables = $this->tableNames($connection);

        $handle = gzopen($destinationPath, 'wb6');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível criar o arquivo de dump: {$destinationPath}.");
        }

        $rows = 0;
        $snapshot = false;

        try {
            $snapshot = $this->beginConsistentSnapshot($connection, $driver);

            $this->write($handle, $this->header($connection, $driver, $tables));

            foreach ($tables as $table) {
                $rows += $this->dumpTable($handle, $connection, $driver, $table);
            }

            $this->write($handle, $this->footer($driver));
        } catch (Throwable $exception) {
            gzclose($handle);
            @unlink($destinationPath);

            throw $exception;
        } finally {
            if ($snapshot) {
                $this->endSnapshot($connection);
            }
        }

        if (gzclose($handle) === false) {
            throw new RuntimeException("Falha ao finalizar o arquivo de dump: {$destinationPath}.");
        }

        $bytes = (int) filesize($destinationPath);
        $sha256 = hash_file('sha256', $destinationPath);

        return new DatabaseDumpResult(
            path: $destinationPath,
            driver: $driver,
            database: (string) $connection->getDatabaseName(),
            tables: count($tables),
            rows: $rows,
            bytes: $bytes,
            sha256: is_string($sha256) ? $sha256 : '',
        );
    }

    /**
     * Tabelas base do banco atual. Views e tabelas internas do SQLite
     * (`sqlite_sequence`, `sqlite_stat1`) ficam de fora: não são dados do
     * domínio e não podem ser recriadas por `INSERT`.
     *
     * @return list<string>
     */
    private function tableNames(ConnectionInterface $connection): array
    {
        $rows = $connection->getDriverName() === 'sqlite'
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

    /**
     * @param  list<string>  $tables
     */
    private function header(ConnectionInterface $connection, string $driver, array $tables): string
    {
        $lines = [
            '-- Arcana Tarot — dump lógico gerado por `php artisan arcana:backup`',
            '-- Driver: '.$driver.' | Banco: '.$connection->getDatabaseName().' | Tabelas: '.count($tables),
            '-- Gerado em: '.now()->utc()->toIso8601String(),
            '-- Restauração: gunzip -c database.sql.gz | mysql -u <usuario> -p <banco>',
            '',
        ];

        if ($driver === 'sqlite') {
            $lines[] = 'PRAGMA foreign_keys = OFF;';
            $lines[] = 'BEGIN TRANSACTION;';
        } else {
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        }

        return implode("\n", $lines)."\n";
    }

    private function footer(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "COMMIT;\nPRAGMA foreign_keys = ON;\n";
        }

        return "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /**
     * Copia estrutura e dados de uma tabela, devolvendo quantas linhas saíram.
     *
     * @param  resource  $handle
     */
    private function dumpTable($handle, ConnectionInterface $connection, string $driver, string $table): int
    {
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);

        $this->write($handle, "\n--\n-- Tabela `{$table}`\n--\n");
        $this->write($handle, "DROP TABLE IF EXISTS {$wrappedTable};\n");
        $this->write($handle, $this->createTableStatement($connection, $driver, $table)."\n");

        $columns = array_values(array_filter(
            $connection->getSchemaBuilder()->getColumns($table),
            /*
             * Colunas geradas (`GENERATED ALWAYS AS`) não aceitam valor no
             * `INSERT`; o servidor recalcula na restauração.
             */
            static fn (array $column): bool => ($column['generation'] ?? null) === null,
        ));

        if ($columns === []) {
            return 0;
        }

        $binaryColumns = [];
        $selectList = [];
        $columnList = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $wrapped = $grammar->wrap($name);

            $binaryColumns[$name] = $this->isBinaryType((string) ($column['type_name'] ?? ''));
            $selectList[] = $connection->raw($binaryColumns[$name] ? 'HEX('.$wrapped.')' : $wrapped);
            $columnList[] = $wrapped;
        }

        $insertPrefix = 'INSERT INTO '.$wrappedTable.' ('.implode(', ', $columnList).') VALUES ';
        $primaryKey = $this->singlePrimaryKey($connection, $table, array_column($columns, 'name'));

        $statement = '';
        $statementRows = 0;
        $total = 0;
        $lastKey = null;
        $offset = 0;

        while (true) {
            $query = $connection->table($table)->select($selectList);

            if ($primaryKey !== null) {
                if ($lastKey !== null) {
                    $query->where($primaryKey, '>', $lastKey);
                }

                $query->orderBy($primaryKey);
            }

            $page = $query
                ->limit(self::PAGE_SIZE)
                ->offset($primaryKey === null ? $offset : 0)
                ->get();

            if ($page->isEmpty()) {
                break;
            }

            foreach ($page as $row) {
                $values = [];

                foreach ($columns as $column) {
                    $name = (string) $column['name'];
                    $values[] = $this->literal($connection, $row->{$name} ?? null, $binaryColumns[$name]);
                }

                $tuple = '('.implode(', ', $values).')';

                $statement = $statementRows === 0 ? $insertPrefix.$tuple : $statement.', '.$tuple;
                $statementRows++;

                if ($statementRows >= self::ROWS_PER_INSERT || strlen($statement) >= self::MAX_STATEMENT_BYTES) {
                    $this->write($handle, $statement.";\n");
                    $statement = '';
                    $statementRows = 0;
                }
            }

            $total += $page->count();

            if ($primaryKey !== null) {
                $lastKey = $page->last()->{$primaryKey};
            } else {
                $offset += self::PAGE_SIZE;
            }

            if ($page->count() < self::PAGE_SIZE) {
                break;
            }
        }

        if ($statementRows > 0) {
            $this->write($handle, $statement.";\n");
        }

        return $total;
    }

    private function createTableStatement(ConnectionInterface $connection, string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            $rows = $connection->select(
                "select sql from sqlite_master where type = 'table' and name = ?",
                [$table],
            );

            $sql = $rows[0]->sql ?? null;

            if (! is_string($sql) || trim($sql) === '') {
                throw new RuntimeException("Tabela sem DDL recuperável em sqlite_master: {$table}.");
            }

            return rtrim(trim($sql), ';').';';
        }

        $rows = $connection->select('SHOW CREATE TABLE '.$connection->getQueryGrammar()->wrapTable($table));
        $values = array_values((array) $rows[0]);
        $sql = trim((string) ($values[1] ?? ''));

        if ($sql === '') {
            throw new RuntimeException("SHOW CREATE TABLE não retornou a estrutura de {$table}.");
        }

        return rtrim($sql, ';').';';
    }

    /**
     * Chave primária simples, usada para paginar sem `OFFSET`. Tabelas com
     * chave composta (ou sem chave) caem no caminho por deslocamento.
     *
     * @param  list<string>  $columns
     */
    private function singlePrimaryKey(ConnectionInterface $connection, string $table, array $columns): ?string
    {
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === false || count($index['columns']) !== 1) {
                continue;
            }

            if (in_array($index['columns'][0], $columns, true)) {
                return (string) $index['columns'][0];
            }
        }

        return null;
    }

    private function isBinaryType(string $typeName): bool
    {
        $type = strtolower($typeName);

        return str_contains($type, 'blob') || str_contains($type, 'binary');
    }

    /**
     * Literal SQL de um valor vindo do banco.
     *
     * Texto é escapado pelo próprio PDO (`quote`), que respeita o `sql_mode` da
     * conexão; binário já chega em hexadecimal; floats usam a representação de
     * ida e volta do PHP (`serialize_precision = -1`), sem perder dígitos.
     */
    private function literal(ConnectionInterface $connection, mixed $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($binary) {
            $hex = (string) $value;

            /*
             * O mesmo dump serve os dois clientes: SQLite só entende blob em
             * `X'...'`, e MySQL/MariaDB em `0x...` (vazio precisa ser `''`,
             * porque `0x` sozinho é erro de sintaxe).
             */
            if ($connection->getDriverName() === 'sqlite') {
                return "X'".$hex."'";
            }

            return $hex === '' ? "''" : '0x'.$hex;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new RuntimeException('Valor numérico não finito encontrado no dump do banco.');
            }

            return var_export($value, true);
        }

        $quoted = $connection->getPdo()->quote((string) $value);

        if ($quoted === false) {
            throw new RuntimeException('Não foi possível escapar um valor de texto do dump do banco.');
        }

        return $quoted;
    }

    /**
     * Inicia a transação de leitura consistente no MySQL/MariaDB. O DDL lido
     * por `SHOW CREATE TABLE` não faz parte do snapshot, mas não muda durante o
     * backup e é lido depois das páginas de dados apenas por legibilidade.
     */
    private function beginConsistentSnapshot(ConnectionInterface $connection, string $driver): bool
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        return true;
    }

    private function endSnapshot(ConnectionInterface $connection): void
    {
        try {
            $connection->statement('COMMIT');
        } catch (Throwable) {
            // Conexão encerrada no meio (timeout do servidor): a transação de
            // leitura já foi desfeita pelo banco e o dump seria descartado.
        }
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $data): void
    {
        if (gzwrite($handle, $data) === false) {
            throw new RuntimeException('Falha ao gravar o dump do banco (disco cheio ou sem permissão).');
        }
    }
}
