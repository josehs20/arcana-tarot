<?php

namespace Tests\Unit;

use App\Support\Backup\SqlScriptReader;
use PHPUnit\Framework\TestCase;

/**
 * Separação de comandos do arquivo `.sql` usado na importação do banco.
 *
 * Um ponto e vírgula dentro de um texto (mensagem de chat, depoimento, nome)
 * não pode cortar o `INSERT`, e um dump real é maior que o bloco de leitura —
 * por isso os casos de borda do buffer também estão aqui.
 */
class SqlScriptReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    public function test_separates_statements_and_ignores_comments(): void
    {
        $statements = $this->read(<<<'SQL'
            -- cabeçalho do dump
            CREATE TABLE "t" (id INTEGER, name TEXT);
            # comentário de linha do MySQL

            INSERT INTO "t" VALUES (1, 'a;b'), (2, '--não é comentário'), (3, 'it''s');
            /* bloco
               de comentário; com ponto e vírgula */
            INSERT INTO "t" VALUES (4, "aspas duplas; aqui");
            SQL);

        $this->assertCount(3, $statements);
        $this->assertSame('CREATE TABLE "t" (id INTEGER, name TEXT)', $statements[0]);
        $this->assertStringContainsString("'a;b'", $statements[1]);
        $this->assertStringContainsString("'--não é comentário'", $statements[1]);
        $this->assertSame('INSERT INTO "t" VALUES (4, "aspas duplas; aqui")', $statements[2]);
    }

    public function test_ignores_transaction_control_from_the_dump(): void
    {
        $statements = $this->read(<<<'SQL'
            PRAGMA foreign_keys = OFF;
            BEGIN TRANSACTION;
            CREATE TABLE t (id INTEGER);
            COMMIT;
            PRAGMA foreign_keys = ON;
            SQL);

        $this->assertSame([
            'PRAGMA foreign_keys = OFF',
            'CREATE TABLE t (id INTEGER)',
            'PRAGMA foreign_keys = ON',
        ], $statements);
    }

    public function test_handles_escaped_quotes_and_backslashes_in_mysql_mode(): void
    {
        $path = $this->file("INSERT INTO t VALUES ('a\\'b;c\\\\', 'd;e');\nINSERT INTO t VALUES ('f');\n");

        $statements = iterator_to_array((new SqlScriptReader)->statements($path, true), false);

        $this->assertCount(2, $statements);
        $this->assertSame("INSERT INTO t VALUES ('a\\'b;c\\\\', 'd;e')", $statements[0]);
        $this->assertSame("INSERT INTO t VALUES ('f')", $statements[1]);
    }

    public function test_reads_a_gzipped_dump_larger_than_the_read_buffer(): void
    {
        $value = str_repeat("texto; com 'aspas' e -- traços ", 6_000);

        $sql = "-- Driver: sqlite\nCREATE TABLE t (body TEXT);\n"
            ."INSERT INTO t VALUES ('".$value."último-pedaço');\n";

        $this->assertGreaterThan(131_072, strlen($sql));

        $path = $this->file(gzencode($sql), 'dump.sql.gz');

        $this->assertTrue((new SqlScriptReader)->isCompressed($path));

        $statements = iterator_to_array((new SqlScriptReader)->statements($path), false);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('INSERT INTO t VALUES', $statements[1]);
        $this->assertStringEndsWith("último-pedaço')", $statements[1]);
        $this->assertStringContainsString('CREATE TABLE t', (new SqlScriptReader)->header($path));
    }

    public function test_returns_the_last_statement_without_a_semicolon(): void
    {
        $this->assertSame(['SELECT 1'], $this->read('SELECT 1'));
    }

    /**
     * @return list<string>
     */
    private function read(string $sql): array
    {
        return iterator_to_array((new SqlScriptReader)->statements($this->file($sql)), false);
    }

    private function file(string $contents, string $suffix = '.sql'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sql-reader-').$suffix;

        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}
