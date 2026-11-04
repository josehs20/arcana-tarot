<?php

namespace App\Console\Commands;

use App\Enums\LegacyImportStatus;
use App\Models\AuditLog;
use App\Support\Legacy\LegacyDumpException;
use App\Support\Legacy\LegacyImportEntry;
use App\Support\Legacy\LegacyImportOptions;
use App\Support\Legacy\LegacyImportPlan;
use App\Support\Legacy\LegacySqlDumpReader;
use App\Support\Legacy\LegacyUserImporter;
use App\Support\Money;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa usuários, senhas compatíveis e saldos do banco legado.
 *
 * A execução padrão é uma simulação: nada é gravado sem `--execute`.
 */
class ImportLegacyUsers extends Command
{
    protected $signature = 'arcana:import-legacy-users
        {file? : Caminho do dump SQL legado}
        {--execute : Grava os usuários; sem a opção apenas simula}
        {--force : Executa mesmo com avisos (dump truncado, tabelas rejeitadas)}
        {--limit= : Lê no máximo esta quantidade de registros}
        {--users-table= : Tabela que contém as credenciais}
        {--email-column= : Coluna de e-mail}
        {--password-column= : Coluna de senha}
        {--name-column= : Coluna de nome}
        {--phone-column= : Coluna de telefone}
        {--user-key-column= : Coluna identificadora do usuário no legado}
        {--credits-table= : Tabela de saldos (ou "none" para não importar saldos)}
        {--credits-user-column= : Coluna que referencia o usuário na tabela de saldos}
        {--credits-balance-column= : Coluna de saldo em reais}';

    protected $description = 'Importa usuários, senhas compatíveis e créditos do banco legado (simulação por padrão)';

    public function handle(LegacyUserImporter $importer): int
    {
        try {
            $options = $this->buildOptions();
            $reader = new LegacySqlDumpReader($options->dumpPath);

            if ($reader->size() === 0) {
                $this->error("Arquivo legado vazio ou ilegível: {$options->dumpPath}");

                return self::FAILURE;
            }

            $plan = $importer->plan($reader, $options->mapping);

            $this->renderHeader($reader, $plan, $options);
            $warnings = $this->renderWarnings($reader);

            if (! $plan->isReady()) {
                $this->newLine();
                $this->error('Importação bloqueada: corrija o mapeamento ou use um dump com credenciais.');

                return self::FAILURE;
            }

            if ($warnings !== [] && $options->execute && ! $options->force) {
                $this->newLine();
                $this->error('Dump com avisos: use --force para importar mesmo assim ou obtenha uma exportação íntegra.');

                return self::FAILURE;
            }

            return $this->runImport($importer, $reader, $plan, $options);
        } catch (LegacyDumpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function buildOptions(): LegacyImportOptions
    {
        $path = $this->argument('file') ?: storage_path('app/imports/astros21.sql');
        $limit = $this->option('limit');

        return new LegacyImportOptions(
            dumpPath: (string) $path,
            execute: (bool) $this->option('execute'),
            force: (bool) $this->option('force'),
            limit: is_numeric($limit) ? (int) $limit : null,
            mapping: array_filter([
                'users_table' => $this->option('users-table'),
                'email_column' => $this->option('email-column'),
                'password_column' => $this->option('password-column'),
                'name_column' => $this->option('name-column'),
                'phone_column' => $this->option('phone-column'),
                'user_key_column' => $this->option('user-key-column'),
                'credits_table' => $this->option('credits-table'),
                'credits_user_column' => $this->option('credits-user-column'),
                'credits_balance_column' => $this->option('credits-balance-column'),
            ], fn (mixed $value): bool => is_string($value) && trim($value) !== ''),
        );
    }

    private function renderHeader(LegacySqlDumpReader $reader, LegacyImportPlan $plan, LegacyImportOptions $options): void
    {
        $this->info($options->execute ? 'Importação de usuários legados (GRAVANDO DADOS)' : 'Importação de usuários legados (SIMULAÇÃO)');
        $this->line('Arquivo: '.$reader->path().' ('.$this->formatBytes($reader->size()).')');
        $this->line('Origem das credenciais: '.$plan->sourceLabel());
        $this->line('Origem dos saldos: '.$plan->creditsLabel());

        $tables = $reader->tables();

        $this->newLine();
        $this->line('Tabelas encontradas no dump ('.count($tables).'):');

        foreach ($tables as $table) {
            $this->line("  - {$table->name} ({$table->describeColumns()})");
        }

        if ($plan->notes !== []) {
            $this->newLine();
            $this->line('Observações:');

            foreach ($plan->notes as $note) {
                $this->line('  - '.$note);
            }
        }

        if ($plan->blockingIssues !== []) {
            $this->newLine();
            $this->line('Problemas bloqueantes:');

            foreach ($plan->blockingIssues as $issue) {
                $this->line('  - '.$issue);
            }
        }

        if ($plan->rejectedCandidates !== []) {
            $this->newLine();
            $this->line('Tabelas descartadas na detecção:');

            foreach ($plan->rejectedCandidates as $candidate) {
                $this->line("  - {$candidate['table']}: {$candidate['reason']}");
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function renderWarnings(LegacySqlDumpReader $reader): array
    {
        $warnings = [];
        $marker = $reader->truncationMarker();

        if ($marker !== null) {
            $warnings[] = "o dump termina com uma mensagem de erro da exportação (\"{$marker}\"); os dados podem estar incompletos.";
        }

        if ($reader->endsWithoutTerminator()) {
            $warnings[] = 'o dump não termina com ";" — a exportação foi interrompida no meio de um comando.';
        }

        if ($warnings !== []) {
            $this->newLine();

            foreach ($warnings as $warning) {
                $this->warn('ATENÇÃO: '.$warning);
            }
        }

        return $warnings;
    }

    private function runImport(
        LegacyUserImporter $importer,
        LegacySqlDumpReader $reader,
        LegacyImportPlan $plan,
        LegacyImportOptions $options,
    ): int {
        $counts = [];

        foreach (LegacyImportStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $credits = 0;
        $total = 0;

        $this->newLine();

        try {
            foreach ($importer->import($reader, $plan, $options->execute, $options->limit) as $entry) {
                $total++;
                $counts[$entry->status->value]++;
                $credits += max(0, $entry->balanceCents);

                $this->renderEntry($entry, $options->execute);
            }
        } catch (LegacyDumpException $exception) {
            $this->error($exception->getMessage());
            $this->renderSummary($counts, $total, $credits, $options->execute);

            return self::FAILURE;
        }

        $this->renderSummary($counts, $total, $credits, $options->execute);

        if ($options->execute && $total > 0) {
            $this->recordBatch($plan, $counts, $total, $credits, $options);
        }

        return $counts[LegacyImportStatus::Failed->value] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function renderEntry(LegacyImportEntry $entry, bool $executed): void
    {
        $details = [];

        if ($entry->isSuccess()) {
            $details[] = $entry->passwordFormat->label();

            if ($entry->balanceCents > 0) {
                $details[] = 'saldo '.Money::format($entry->balanceCents);
            }
        }

        if ($entry->reason !== null) {
            $details[] = $entry->reason;
        }

        $this->line(sprintf(
            '  [%-16s] %-38s %s',
            $entry->status->label($executed),
            $entry->email ?? $entry->legacyKey,
            implode(' | ', $details),
        ));
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderSummary(array $counts, int $total, int $credits, bool $executed): void
    {
        $this->newLine();
        $this->line("Registros lidos: {$total}");

        foreach (LegacyImportStatus::cases() as $status) {
            $count = $counts[$status->value];

            if ($count > 0) {
                $this->line('  '.$status->label($executed).': '.$count);
            }
        }

        $this->line(($executed ? 'Créditos importados: ' : 'Créditos que seriam importados: ').Money::format($credits));

        if (! $executed) {
            $this->newLine();
            $this->warn('Simulação concluída: nenhum dado foi gravado. Use --execute para gravar.');
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function recordBatch(
        LegacyImportPlan $plan,
        array $counts,
        int $total,
        int $credits,
        LegacyImportOptions $options,
    ): void {
        try {
            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'user.import_batch_from_legacy',
                'metadata' => [
                    'dump_path' => $options->dumpPath,
                    'source_table' => $plan->userTable?->name,
                    'legacy_key_column' => $plan->legacyKeyColumn,
                    'records' => $total,
                    'totals' => $counts,
                    'credits_cents' => $credits,
                    'forced' => $options->force,
                ],
            ]);

            $this->line('Lote registrado na trilha de auditoria (user.import_batch_from_legacy).');
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('Não foi possível registrar o lote na trilha de auditoria; consulte os logs.');
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return $bytes.' bytes';
    }
}
