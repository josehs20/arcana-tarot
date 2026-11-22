<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupManager;
use App\Support\Backup\ByteSize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Gera o backup do banco e do storage, mantendo apenas o mais recente.
 *
 * Agendado a cada seis horas em `routes/console.php`. O trabalho pesado fica em
 * {@see BackupManager}: aqui só entram as opções de linha de comando, o lock que
 * impede duas execuções simultâneas (agendada + manual) e o relatório para o
 * operador, com caminho, tamanho, contagens e o que a retenção removeu.
 *
 * Falha do comando significa backup NÃO gravado — o anterior continua no lugar.
 * O cron da Hostoo manda a saída para o log do scheduler, então o erro aparece
 * também no `storage/logs/laravel.log`.
 */
class GenerateBackup extends Command
{
    private const LOCK_KEY = 'arcana:backup';

    /**
     * O lock expira sozinho: um processo morto no meio não pode bloquear as
     * próximas execuções (o scheduler roda a cada seis horas).
     */
    private const LOCK_SECONDS = 7_200;

    protected $signature = 'arcana:backup
        {--destination= : Diretório de destino (padrão: BACKUP_DIRECTORY ou storage/backups)}
        {--keep= : Quantos conjuntos manter (padrão: BACKUP_KEEP ou 1)}
        {--database-only : Salva apenas o dump do banco, sem os arquivos de storage}';

    protected $description = 'Gera backup do banco e do storage, mantendo somente os conjuntos mais recentes';

    public function handle(BackupManager $manager): int
    {
        $keep = $this->keepOption();

        if ($keep === null) {
            return self::FAILURE;
        }

        $destination = $this->destinationOption();
        $storagePaths = $this->option('database-only')
            ? []
            : $this->storagePaths();

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('Outro backup está em execução; esta execução foi ignorada.');

            return self::SUCCESS;
        }

        try {
            $result = $manager->run($destination, $keep, $storagePaths);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup não foi gravado: '.$exception->getMessage());
            $this->line('O backup anterior (se existia) continua intacto.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info('Backup concluído: '.$this->relativePath($result->directory));
        $this->line(sprintf(
            'Banco: %s — %d tabela(s), %d linha(s)',
            ByteSize::format($result->database->bytes),
            $result->database->tables,
            $result->database->rows,
        ));

        if ($result->files !== null) {
            $this->line(sprintf(
                'Storage: %s — %d arquivo(s)',
                ByteSize::format($result->files->bytes),
                $result->files->files,
            ));
        }

        $this->line(sprintf(
            'Total: %s em %.1f s',
            ByteSize::format($result->totalBytes()),
            $result->seconds,
        ));

        if ($result->pruned !== []) {
            $this->line('Retenção: removido(s) '.implode(', ', $result->pruned).'.');
        }

        if ($result->skipped !== []) {
            $this->warn(sprintf(
                'Itens fora do arquivo de storage (%d): %s',
                count($result->skipped),
                implode(', ', array_slice($result->skipped, 0, 10)).(count($result->skipped) > 10 ? '…' : ''),
            ));
        }

        return self::SUCCESS;
    }

    private function keepOption(): ?int
    {
        $option = $this->option('keep');
        $keep = $option === null ? (int) config('backup.keep') : (int) $option;

        if ($keep < 1) {
            $this->error('A opção --keep precisa ser um número inteiro maior ou igual a 1.');

            return null;
        }

        return $keep;
    }

    private function destinationOption(): string
    {
        $option = $this->option('destination');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return (string) config('backup.directory');
    }

    /**
     * @return list<string>
     */
    private function storagePaths(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $path): string => trim((string) $path),
                (array) config('backup.storage_paths'),
            ),
            static fn (string $path): bool => $path !== '',
        ));
    }

    private function relativePath(string $path): string
    {
        $root = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
