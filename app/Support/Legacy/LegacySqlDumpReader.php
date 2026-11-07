<?php

namespace App\Support\Legacy;

use Generator;

/**
 * Leitor incremental de dumps SQL (mysqldump/phpMyAdmin) usado na importação
 * do banco legado.
 *
 * O arquivo é lido em blocos, sem `file_get_contents`, porque o dump real tem
 * centenas de megabytes. O leitor apenas *interpreta* o dump: nenhum trecho do
 * arquivo é executado como SQL. A primeira passagem coleta os `CREATE TABLE`;
 * a segunda percorre os `INSERT` da tabela escolhida.
 */
final class LegacySqlDumpReader implements LegacyDumpSource
{
    private const CHUNK_BYTES = 1_048_576;

    private const WORD_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$';

    private const WHITESPACE_CHARS = " \t\r\n";

    private const DEFINITION_STOP = "(),'`;";

    private const STATEMENT_STOP = ";'\"`#-/";

    private const INSERT_MODIFIERS = ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'];

    /** @var resource|null */
    private $handle = null;

    private string $buffer = '';

    private int $offset = 0;

    private bool $eof = false;

    /** @var array<string, LegacyDumpTable>|null */
    private ?array $tables = null;

    public function __construct(private readonly string $path) {}

    public function __destruct()
    {
        $this->close();
    }

    public function files(): array
    {
        return [['path' => $this->path, 'size' => $this->size()]];
    }

    public function sourceLabel(): string
    {
        return 'dump SQL';
    }

    public function size(): int
    {
        return LegacyDumpTail::size($this->path);
    }

    public function integrityIssues(): array
    {
        $marker = LegacyDumpTail::truncationMarker($this->path);
        $unterminated = $this->endsWithoutTerminator();

        if ($marker === null && ! $unterminated) {
            return [];
        }

        return [['path' => $this->path, 'marker' => $marker, 'unterminated' => $unterminated]];
    }

    /**
     * Indica que o arquivo termina sem o `;` final de um comando, sinal de
     * exportação interrompida no meio de um `INSERT`.
     */
    private function endsWithoutTerminator(): bool
    {
        $size = $this->size();

        if ($size === 0) {
            return false;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new LegacyDumpException('Não foi possível abrir o arquivo legado para leitura.');
        }

        $length = min($size, 4_096);
        fseek($handle, $size - $length);
        $tail = rtrim((string) fread($handle, $length), self::WHITESPACE_CHARS);
        fclose($handle);

        return $tail !== '' && ! str_ends_with($tail, ';');
    }

    /**
     * Lê a estrutura declarada no dump.
     *
     * @return array<string, LegacyDumpTable> índice em minúsculas para busca
     */
    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        return $this->tables = $this->readTables();
    }

    /**
     * @return array<string, LegacyDumpTable>
     */
    private function readTables(): array
    {
        $this->open();
        $tables = [];

        while (true) {
            $this->skipWhitespaceAndComments();

            if ($this->peek() === null) {
                break;
            }

            $word = strtoupper($this->readWord());

            if ($word === '') {
                $this->advance();

                continue;
            }

            if ($word === 'CREATE') {
                $table = $this->readCreateTable();

                if ($table !== null) {
                    $tables[mb_strtolower($table->name)] = $table;
                }

                continue;
            }

            $this->skipStatement();
        }

        $this->close();

        return $tables;
    }

    /**
     * Percorre os registros de uma tabela declarada no dump.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(LegacyDumpTable $table): Generator
    {
        $this->open();

        while (true) {
            $this->skipWhitespaceAndComments();

            if ($this->peek() === null) {
                break;
            }

            $word = strtoupper($this->readWord());

            if ($word === '') {
                $this->advance();

                continue;
            }

            if ($word !== 'INSERT' && $word !== 'REPLACE') {
                $this->skipStatement();

                continue;
            }

            $this->skipWhitespaceAndComments();
            $modifier = strtoupper($this->readWord());

            while (in_array($modifier, self::INSERT_MODIFIERS, true)) {
                $this->skipWhitespaceAndComments();
                $modifier = strtoupper($this->readWord());
            }

            if ($modifier !== 'INTO') {
                $this->skipStatement();

                continue;
            }

            $this->skipWhitespaceAndComments();
            $name = $this->readIdentifier();

            if ($name === null || strcasecmp($name, $table->name) !== 0) {
                $this->skipStatement();

                continue;
            }

            yield from $this->readInsertValues($table);
        }

        $this->close();
    }

    private function open(): void
    {
        if ($this->handle === null) {
            $handle = @fopen($this->path, 'rb');

            if ($handle === false) {
                throw new LegacyDumpException('Não foi possível abrir o arquivo legado para leitura.');
            }

            $this->handle = $handle;
        }

        rewind($this->handle);
        $this->buffer = '';
        $this->offset = 0;
        $this->eof = false;
    }

    private function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->buffer = '';
        $this->offset = 0;
        $this->eof = true;
    }

    private function readCreateTable(): ?LegacyDumpTable
    {
        $this->skipWhitespaceAndComments();
        $word = strtoupper($this->readWord());

        if ($word === 'TEMPORARY') {
            $this->skipWhitespaceAndComments();
            $word = strtoupper($this->readWord());
        }

        if ($word !== 'TABLE') {
            $this->skipStatement();

            return null;
        }

        $this->skipWhitespaceAndComments();

        if (strtoupper($this->readWord()) === 'IF') {
            $this->skipWhitespaceAndComments();
            $this->readWord();
            $this->skipWhitespaceAndComments();
            $this->readWord();
        }

        $name = $this->readIdentifier();

        if ($name === null) {
            $this->skipStatement();

            return null;
        }

        $this->skipWhitespaceAndComments();

        if ($this->peek() !== '(') {
            $this->skipStatement();

            return null;
        }

        $this->advance();
        $columns = [];

        while (true) {
            [$definition, $closed] = $this->readDefinitionItem();

            if ($definition !== '') {
                $column = $this->parseColumnDefinition($definition);

                if ($column !== null) {
                    $columns[strtolower($column->name)] = $column;
                }
            }

            if ($closed) {
                break;
            }
        }

        $this->skipStatement();

        return new LegacyDumpTable($name, array_values($columns));
    }

    /**
     * Consome a definição de uma coluna/índice até a `,` ou a `)` de
     * fechamento da lista de colunas.
     *
     * @return array{0: string, 1: bool} definição e indicação de lista encerrada
     */
    private function readDefinitionItem(): array
    {
        $item = '';
        $depth = 0;

        while (true) {
            if (! $this->ensure(1)) {
                return [trim($item), true];
            }

            $char = $this->buffer[$this->offset];

            if ($char === "'" || $char === '"' || $char === '`') {
                $item .= $this->readQuotedRaw($char);

                continue;
            }

            if ($char === '(') {
                $depth++;
                $item .= $char;
                $this->advance();

                continue;
            }

            if ($char === ')') {
                $this->advance();

                if ($depth === 0) {
                    return [trim($item), true];
                }

                $depth--;
                $item .= $char;

                continue;
            }

            if (($char === ',' || $char === ';') && $depth === 0) {
                $this->advance();

                return [trim($item), $char === ';'];
            }

            $length = strcspn($this->buffer, self::DEFINITION_STOP, $this->offset);

            if ($length > 0) {
                $item .= substr($this->buffer, $this->offset, $length);
                $this->offset += $length;

                continue;
            }

            $item .= $char;
            $this->advance();
        }
    }

    private function parseColumnDefinition(string $definition): ?LegacyDumpColumn
    {
        if (preg_match('/^(?:PRIMARY\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY|FULLTEXT|SPATIAL|CHECK)\b/i', $definition) === 1) {
            return null;
        }

        if (preg_match('/^`?([A-Za-z0-9_$]+)`?\s+([A-Za-z]+)/', $definition, $matches) !== 1) {
            return null;
        }

        return new LegacyDumpColumn(
            name: $matches[1],
            type: strtolower($matches[2]),
            nullable: preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1,
        );
    }

    /**
     * Lê as tuplas de um `INSERT`/`REPLACE` da tabela escolhida.
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function readInsertValues(LegacyDumpTable $table): Generator
    {
        $this->skipWhitespaceAndComments();
        $columns = $table->columnNames();

        if ($this->peek() === '(') {
            $this->advance();
            $columns = [];

            while (true) {
                $this->skipWhitespaceAndComments();
                $identifier = $this->readIdentifier();

                if ($identifier !== null) {
                    $columns[] = $identifier;
                }

                $this->skipWhitespaceAndComments();
                $separator = $this->peek();

                if ($separator === ',') {
                    $this->advance();

                    continue;
                }

                if ($separator === ')') {
                    $this->advance();

                    break;
                }

                throw new LegacyDumpException(
                    "Lista de colunas inválida no INSERT da tabela {$table->name}.",
                );
            }
        }

        $this->skipWhitespaceAndComments();

        if (strtoupper($this->readWord()) !== 'VALUES') {
            throw new LegacyDumpException("Comando INSERT sem VALUES na tabela {$table->name}.");
        }

        while (true) {
            $this->skipWhitespaceAndComments();
            $start = $this->peek();

            if ($start === null) {
                return;
            }

            if ($start === ';') {
                $this->advance();

                return;
            }

            if ($start !== '(') {
                throw new LegacyDumpException("Registro inesperado no INSERT da tabela {$table->name}.");
            }

            $this->advance();
            $values = [];
            $index = 0;

            while (true) {
                $value = $this->readValue();

                if ($value === false) {
                    return;
                }

                $values[$columns[$index] ?? $index] = $value;
                $index++;
                $this->skipWhitespace();
                $separator = $this->peek();

                if ($separator === ',') {
                    $this->advance();

                    continue;
                }

                if ($separator === ')') {
                    $this->advance();

                    break;
                }

                if ($separator === null) {
                    return;
                }

                throw new LegacyDumpException("Registro incompleto no INSERT da tabela {$table->name}.");
            }

            yield $values;

            $this->skipWhitespace();
            $separator = $this->peek();

            if ($separator === ',') {
                $this->advance();

                continue;
            }

            if ($separator === ';') {
                $this->advance();

                return;
            }

            if ($separator === null) {
                return;
            }

            throw new LegacyDumpException("INSERT malformado na tabela {$table->name}.");
        }
    }

    /**
     * Lê um valor escalar. Retorna `false` quando o registro termina antes do
     * valor (arquivo truncado).
     */
    private function readValue(): string|false|null
    {
        $this->skipWhitespace();
        $char = $this->peek();

        if ($char === null) {
            return false;
        }

        if ($char === "'" || $char === '"') {
            return $this->readStringValue($char);
        }

        if ($char === '0' && ($this->peek(1) === 'x' || $this->peek(1) === 'X')) {
            $this->advance(2);
            $hex = $this->readWord();
            $binary = $hex === '' ? '' : (hex2bin(str_pad($hex, (int) ceil(strlen($hex) / 2) * 2, '0')) ?: '');

            return $this->normalizeEncoding($binary);
        }

        $start = $this->offset;
        $word = $this->readWord();

        if (strtoupper($word) === '_BINARY') {
            $this->skipWhitespace();
            $quote = $this->peek();

            if ($quote === "'" || $quote === '"') {
                return $this->readStringValue($quote);
            }
        }

        $this->offset = $start;
        $token = trim($this->readBareToken(',)'));

        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }

        if ($token === '') {
            return false;
        }

        return $this->normalizeEncoding($token);
    }

    /**
     * Lê o conteúdo de uma string SQL aplicando os escapes do MySQL.
     */
    private function readStringValue(string $quote): string
    {
        $this->advance();
        $value = '';
        $mask = '\\'.$quote;

        while (true) {
            if (! $this->ensure(1)) {
                break;
            }

            $length = strcspn($this->buffer, $mask, $this->offset);

            if ($length > 0) {
                $value .= substr($this->buffer, $this->offset, $length);
                $this->offset += $length;
            }

            $char = $this->peek();

            if ($char === null) {
                break;
            }

            if ($char === '\\') {
                $this->advance();
                $escaped = $this->peek();

                if ($escaped === null) {
                    break;
                }

                $this->advance();
                $value .= match ($escaped) {
                    '0' => "\0",
                    'b' => "\x08",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'Z' => "\x1a",
                    default => $escaped,
                };

                continue;
            }

            $this->advance();

            if ($this->peek() === $quote) {
                $this->advance();
                $value .= $quote;

                continue;
            }

            break;
        }

        return $this->normalizeEncoding($value);
    }

    /**
     * Devolve o texto bruto (com aspas e escapes) de um literal, usado apenas
     * na leitura de definições de tabela.
     */
    private function readQuotedRaw(string $quote): string
    {
        $raw = $quote;
        $this->advance();
        $mask = '\\'.$quote;

        while (true) {
            if (! $this->ensure(1)) {
                return $raw;
            }

            $length = strcspn($this->buffer, $mask, $this->offset);

            if ($length > 0) {
                $raw .= substr($this->buffer, $this->offset, $length);
                $this->offset += $length;
            }

            $char = $this->peek();

            if ($char === null) {
                return $raw;
            }

            if ($char === '\\') {
                $raw .= $char;
                $this->advance();
                $next = $this->peek();

                if ($next !== null) {
                    $raw .= $next;
                    $this->advance();
                }

                continue;
            }

            $raw .= $char;
            $this->advance();

            if ($this->peek() === $quote) {
                $raw .= $quote;
                $this->advance();

                continue;
            }

            return $raw;
        }
    }

    private function readBareToken(string $stopChars): string
    {
        $token = '';

        while (true) {
            if (! $this->ensure(1)) {
                break;
            }

            $length = strcspn($this->buffer, $stopChars, $this->offset);

            if ($length === 0) {
                break;
            }

            $token .= substr($this->buffer, $this->offset, $length);
            $this->offset += $length;
        }

        return $token;
    }

    private function readWord(): string
    {
        $word = '';

        while (true) {
            if (! $this->ensure(1)) {
                break;
            }

            $length = strspn($this->buffer, self::WORD_CHARS, $this->offset);

            if ($length === 0) {
                break;
            }

            $word .= substr($this->buffer, $this->offset, $length);
            $this->offset += $length;
        }

        return $word;
    }

    private function readIdentifier(): ?string
    {
        $this->skipWhitespaceAndComments();
        $identifier = $this->readIdentifierPart();

        if ($identifier === null) {
            return null;
        }

        while ($this->peek() === '.') {
            $this->advance();
            $next = $this->readIdentifierPart();

            if ($next === null) {
                break;
            }

            $identifier = $next;
        }

        return $identifier;
    }

    private function readIdentifierPart(): ?string
    {
        $char = $this->peek();

        if ($char === null) {
            return null;
        }

        if ($char === '`') {
            $this->advance();
            $name = '';

            while (true) {
                if (! $this->ensure(1)) {
                    return $name;
                }

                $length = strcspn($this->buffer, '`', $this->offset);

                if ($length > 0) {
                    $name .= substr($this->buffer, $this->offset, $length);
                    $this->offset += $length;
                }

                if ($this->peek() !== '`') {
                    return $name;
                }

                $this->advance();

                if ($this->peek() === '`') {
                    $this->advance();
                    $name .= '`';

                    continue;
                }

                return $name;
            }
        }

        $word = $this->readWord();

        return $word === '' ? null : $word;
    }

    private function skipWhitespace(): void
    {
        while (true) {
            if (! $this->ensure(1)) {
                return;
            }

            $length = strspn($this->buffer, self::WHITESPACE_CHARS, $this->offset);

            if ($length === 0) {
                return;
            }

            $this->offset += $length;
        }
    }

    private function skipWhitespaceAndComments(): void
    {
        while (true) {
            $this->skipWhitespace();
            $char = $this->peek();

            if ($char === '#') {
                $this->skipLineComment();

                continue;
            }

            if ($char === '-' && $this->peek(1) === '-') {
                $this->skipLineComment();

                continue;
            }

            if ($char === '/' && $this->peek(1) === '*') {
                $this->skipBlockComment();

                continue;
            }

            return;
        }
    }

    /**
     * Consome um comando SQL inteiro, respeitando strings e comentários.
     */
    private function skipStatement(): void
    {
        while (true) {
            if (! $this->ensure(1)) {
                return;
            }

            $length = strcspn($this->buffer, self::STATEMENT_STOP, $this->offset);

            if ($length > 0) {
                $this->offset += $length;
            }

            $char = $this->peek();

            if ($char === null) {
                return;
            }

            if ($char === ';') {
                $this->advance();

                return;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $this->skipQuoted($char);

                continue;
            }

            if ($char === '#') {
                $this->skipLineComment();

                continue;
            }

            if ($char === '-' && $this->peek(1) === '-') {
                $this->skipLineComment();

                continue;
            }

            if ($char === '/' && $this->peek(1) === '*') {
                $this->skipBlockComment();

                continue;
            }

            $this->advance();
        }
    }

    private function skipQuoted(string $quote): void
    {
        $this->advance();
        $mask = '\\'.$quote;

        while (true) {
            if (! $this->ensure(1)) {
                return;
            }

            $length = strcspn($this->buffer, $mask, $this->offset);

            if ($length > 0) {
                $this->offset += $length;
            }

            $char = $this->peek();

            if ($char === null) {
                return;
            }

            if ($char === '\\') {
                $this->advance();

                if ($this->peek() !== null) {
                    $this->advance();
                }

                continue;
            }

            $this->advance();

            if ($this->peek() === $quote) {
                $this->advance();

                continue;
            }

            return;
        }
    }

    private function skipLineComment(): void
    {
        while (true) {
            if (! $this->ensure(1)) {
                return;
            }

            $position = strpos($this->buffer, "\n", $this->offset);

            if ($position !== false) {
                $this->offset = $position + 1;

                return;
            }

            $this->offset = strlen($this->buffer);

            if ($this->eof || ! $this->ensure(1)) {
                return;
            }
        }
    }

    private function skipBlockComment(): void
    {
        $this->advance(2);

        while (true) {
            if (! $this->ensure(1)) {
                return;
            }

            $position = strpos($this->buffer, '*/', $this->offset);

            if ($position !== false) {
                $this->offset = $position + 2;

                return;
            }

            $this->offset = strlen($this->buffer);

            if ($this->eof || ! $this->ensure(1)) {
                return;
            }
        }
    }

    private function peek(int $ahead = 0): ?string
    {
        if (! $this->ensure($ahead + 1)) {
            return null;
        }

        return $this->buffer[$this->offset + $ahead];
    }

    private function advance(int $bytes = 1): void
    {
        $this->offset += $bytes;
    }

    /**
     * Garante `$bytes` caracteres disponíveis a partir do deslocamento atual,
     * descartando o trecho já consumido e lendo o próximo bloco quando preciso.
     */
    private function ensure(int $bytes): bool
    {
        while (strlen($this->buffer) - $this->offset < $bytes && ! $this->eof) {
            if ($this->offset > 0) {
                $this->buffer = substr($this->buffer, $this->offset);
                $this->offset = 0;
            }

            $data = $this->handle === null ? false : fread($this->handle, self::CHUNK_BYTES);

            if ($data === false || $data === '') {
                $this->eof = true;

                break;
            }

            $this->buffer .= $data;
        }

        return strlen($this->buffer) - $this->offset >= $bytes;
    }

    /**
     * O dump legado mistura latin1 e utf8mb3; textos inválidos em UTF-8 são
     * convertidos para não corromper o banco novo.
     */
    private function normalizeEncoding(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        return is_string($converted) ? $converted : $value;
    }
}
