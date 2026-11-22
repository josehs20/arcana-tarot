<?php

namespace App\Support\Backup;

use Generator;
use RuntimeException;

/**
 * Leitor de scripts SQL usados na importação do banco.
 *
 * O arquivo é lido em blocos (nunca inteiro na memória) e entregue comando a
 * comando, já sem o `;` final e sem comentários. Aceita `.sql` puro ou `.sql.gz`
 * — o mesmo formato que o `arcana:backup` grava — e detecta o gzip pelos bytes
 * iniciais, não pela extensão.
 *
 * O separador é encontrado respeitando strings (`'...'`, `"..."`, backticks),
 * escapes (`''` nas duas sintaxes e `\'` no MySQL/MariaDB), comentários de linha
 * (`--`, `#`) e de bloco. Sem isso um ponto e vírgula dentro de uma mensagem de
 * chat, por exemplo, cortaria o `INSERT` no meio.
 *
 * Limitações conhecidas: `DELIMITER`, *triggers*, *procedures* e `events` não
 * são suportados (o projeto não usa nenhum deles e o dump gerado aqui também
 * não os inclui). Comentários condicionais do `mysqldump` são tratados como
 * comentário comum e descartados.
 */
final class SqlScriptReader
{
    private const CHUNK_BYTES = 65_536;

    /**
     * Bytes que ficam para a próxima rodada: os tokens que mudam o estado
     * (`--`, `/*`, `*` + `/`, barra invertida) precisam de até três bytes de
     * lookahead.
     */
    private const LOOKAHEAD = 3;

    /**
     * Comandos de controle de transação do dump são ignorados: a importação
     * roda em autocommit e quem controla a atomicidade é o backup de segurança
     * gravado antes dela.
     */
    private const IGNORED_STATEMENTS = [
        'BEGIN',
        'BEGIN TRANSACTION',
        'START TRANSACTION',
        'COMMIT',
        'END',
        'END TRANSACTION',
    ];

    /**
     * @return Generator<int, string>
     */
    public function statements(string $path, bool $backslashEscapes = true): Generator
    {
        [$handle, $compressed] = $this->open($path);

        $pending = '';
        $statement = '';
        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;
        $markers = ";'\"`-/".($backslashEscapes ? '#' : '');

        try {
            while (true) {
                $chunk = $compressed ? gzread($handle, self::CHUNK_BYTES) : fread($handle, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException("Falha ao ler o arquivo SQL de importação: {$path}.");
                }

                $eof = $chunk === '';

                if (! $eof) {
                    $pending .= $chunk;
                }

                if ($pending === '') {
                    break;
                }

                $length = strlen($pending);
                $limit = $eof ? $length : max(0, $length - self::LOOKAHEAD);
                $offset = 0;

                while ($offset < $limit) {
                    if ($inLineComment) {
                        $newline = strpos($pending, "\n", $offset);

                        if ($newline === false || $newline >= $limit) {
                            $offset = $limit;

                            break;
                        }

                        $inLineComment = false;
                        $offset = $newline + 1;

                        continue;
                    }

                    if ($inBlockComment) {
                        $end = strpos($pending, '*/', $offset);

                        if ($end === false || $end + 2 > $limit) {
                            $offset = $limit;

                            break;
                        }

                        $inBlockComment = false;
                        $offset = $end + 2;

                        continue;
                    }

                    if ($quote !== null) {
                        $stop = $offset + strcspn($pending, $quote.($backslashEscapes ? '\\' : ''), $offset);

                        if ($stop >= $limit) {
                            $statement .= substr($pending, $offset, $limit - $offset);
                            $offset = $limit;

                            break;
                        }

                        $current = $pending[$stop];

                        if ($backslashEscapes && $current === '\\') {
                            $statement .= substr($pending, $offset, $stop - $offset + 2);
                            $offset = $stop + 2;

                            continue;
                        }

                        if ($current === $quote && $stop + 1 < $length && $pending[$stop + 1] === $quote) {
                            $statement .= substr($pending, $offset, $stop - $offset + 2);
                            $offset = $stop + 2;

                            continue;
                        }

                        $statement .= substr($pending, $offset, $stop - $offset + 1);
                        $quote = null;
                        $offset = $stop + 1;

                        continue;
                    }

                    $stop = $offset + strcspn($pending, $markers, $offset);

                    if ($stop >= $limit) {
                        /*
                         * Nenhum token nesta rodada: o texto puro entra no
                         * comando e o restante (que pode começar com aspas,
                         * comentário ou `;`) fica para a próxima leitura.
                         */
                        $statement .= substr($pending, $offset, $stop - $offset);
                        $offset = $stop;

                        break;
                    }

                    $current = $pending[$stop];

                    if ($current === ';') {
                        $candidate = trim($statement.substr($pending, $offset, $stop - $offset));
                        $statement = '';
                        $offset = $stop + 1;

                        if ($candidate !== '' && ! $this->isIgnored($candidate)) {
                            yield $candidate;
                        }

                        continue;
                    }

                    if ($current === "'" || $current === '"' || $current === '`') {
                        $statement .= substr($pending, $offset, $stop - $offset + 1);
                        $quote = $current;
                        $offset = $stop + 1;

                        continue;
                    }

                    if ($current === '#') {
                        $statement .= substr($pending, $offset, $stop - $offset);
                        $inLineComment = true;
                        $offset = $stop + 1;

                        continue;
                    }

                    if ($current === '-') {
                        $isComment = $stop + 1 < $length
                            && $pending[$stop + 1] === '-'
                            && ($stop + 2 >= $length || ctype_space($pending[$stop + 2]));

                        if ($isComment) {
                            $statement .= substr($pending, $offset, $stop - $offset);
                            $inLineComment = true;
                            $offset = $stop + 2;

                            continue;
                        }

                        $statement .= substr($pending, $offset, $stop - $offset + 1);
                        $offset = $stop + 1;

                        continue;
                    }

                    if ($current === '/' && $stop + 1 < $length && $pending[$stop + 1] === '*') {
                        $statement .= substr($pending, $offset, $stop - $offset);
                        $inBlockComment = true;
                        $offset = $stop + 2;

                        continue;
                    }

                    $statement .= substr($pending, $offset, $stop - $offset + 1);
                    $offset = $stop + 1;
                }

                /*
                 * O que não foi processado continua no buffer e é revisto com o
                 * próximo bloco: nada é copiado para o comando sem passar pelo
                 * reconhecimento de string/comentário.
                 */
                $pending = substr($pending, $offset);

                if ($eof) {
                    break;
                }
            }
        } finally {
            $this->close($handle, $compressed);
        }

        /*
         * Dumps de algumas ferramentas não terminam o último comando com `;`.
         * O resto é executado como comando final se tiver conteúdo.
         */
        $last = trim($statement);

        if (str_ends_with($last, ';')) {
            $last = trim(substr($last, 0, -1));
        }

        if ($last !== '' && ! $this->isIgnored($last)) {
            yield $last;
        }
    }

    /**
     * Primeiras linhas do arquivo, usadas para conferir o cabeçalho do dump
     * (`-- Driver: mysql`) antes de apagar qualquer tabela.
     */
    public function header(string $path, int $bytes = 512): string
    {
        [$handle, $compressed] = $this->open($path);

        try {
            $header = $compressed ? gzread($handle, $bytes) : fread($handle, $bytes);
        } finally {
            $this->close($handle, $compressed);
        }

        return $header === false ? '' : $header;
    }

    public function isCompressed(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Arquivo de importação não encontrado: {$path}.");
        }

        $magic = fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    /**
     * @return array{0: resource, 1: bool}
     */
    private function open(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Arquivo de importação não encontrado ou sem permissão de leitura: {$path}.");
        }

        if ($this->isCompressed($path)) {
            $handle = gzopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Não foi possível abrir o arquivo comprimido: {$path}.");
            }

            return [$handle, true];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir o arquivo: {$path}.");
        }

        return [$handle, false];
    }

    /**
     * @param  resource  $handle
     */
    private function close($handle, bool $compressed): void
    {
        if ($compressed) {
            gzclose($handle);

            return;
        }

        fclose($handle);
    }

    private function isIgnored(string $statement): bool
    {
        $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $statement)));

        return in_array($normalized, self::IGNORED_STATEMENTS, true);
    }
}
