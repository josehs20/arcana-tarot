<?php

namespace App\Console\Commands;

use App\Enums\LegacyImportStatus;
use App\Models\AuditLog;
use App\Support\Legacy\LegacyDumpException;
use App\Support\Legacy\LegacyDumpFactory;
use App\Support\Legacy\LegacyDumpSource;
use App\Support\Legacy\LegacyImportEntry;
use App\Support\Legacy\LegacyImportOptions;
use App\Support\Legacy\LegacyImportPlan;
use App\Support\Legacy\LegacyUserImporter;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa usuários, senhas compatíveis, dados cadastrais e saldos do banco
 * legado (`eam_usuarios` + `eam_clientes`).
 *
 * O mapeamento do banco é fixo (`LegacySchemaPreset`): este comando importa um
 * único legado, então não há opções de tabela/coluna. A exportação pode ser um
 * dump SQL, um CSV com todos os blocos, um arquivo por tabela ou um diretório
 * com eles — os arquivos informados são lidos como uma origem só. A execução
 * padrão é uma simulação: nada é gravado sem `--execute`.
 */
class ImportLegacyUsers extends Command
{
    protected $signature = 'arcana:import-legacy-users
        {file?* : Arquivos ou diretório da exportação legada (padrão: storage/app/imports)}
        {--execute : Grava os usuários; sem a opção apenas simula}
        {--force : Executa mesmo com avisos (exportação truncada)}
        {--limit= : Lê no máximo esta quantidade de registros}';

    protected $description = 'Importa usuários, senhas compatíveis, dados cadastrais e créditos do banco legado (simulação por padrão)';

    public function handle(LegacyUserImporter $importer): int
    {
        try {
            $options = $this->buildOptions();
            $reader = LegacyDumpFactory::open($options->sourcePaths);

            if ($reader->size() === 0) {
                $this->error("Arquivo legado vazio ou ilegível: {$options->dumpPath}");

                return self::FAILURE;
            }

            $plan = $importer->plan($reader);

            $this->renderHeader($reader, $plan, $options);
            $warnings = $this->renderWarnings($reader);

            if (! $plan->isReady()) {
                $this->newLine();
                $this->error('Importação bloqueada: corrija os problemas acima antes de importar.');

                return self::FAILURE;
            }

            if ($warnings !== [] && $options->execute && ! $options->force) {
                $this->newLine();
                $this->error('Arquivo com avisos: use --force para importar mesmo assim ou obtenha uma exportação íntegra.');

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
        $files = array_values(array_filter(
            array_map(static fn (string|int|float|null $path): string => trim((string) $path), (array) $this->argument('file')),
            static fn (string $path): bool => $path !== '',
        ));

        $limit = $this->option('limit');

        return new LegacyImportOptions(
            dumpPath: $files === [] ? storage_path('app/imports') : $files,
            execute: (bool) $this->option('execute'),
            force: (bool) $this->option('force'),
            limit: is_numeric($limit) ? (int) $limit : null,
        );
    }

    private function renderHeader(LegacyDumpSource $reader, LegacyImportPlan $plan, LegacyImportOptions $options): void
    {
        $this->info($options->execute ? 'Importação de usuários legados (GRAVANDO DADOS)' : 'Importação de usuários legados (SIMULAÇÃO)');

        $files = $reader->files();
        $single = count($files) === 1;

        if ($single) {
            $this->line('Arquivo: '.$files[0]['path'].' ('.$this->formatBytes($reader->size()).')');
        } else {
            $this->line('Arquivos ('.count($files).', '.$this->formatBytes($reader->size()).'):');

            foreach ($files as $file) {
                $this->line('  - '.$file['path'].' ('.$this->formatBytes($file['size']).')');
            }
        }

        $this->line('Origem das credenciais: '.$plan->sourceLabel());
        $this->line('Origem dos saldos: '.$plan->creditsLabel());
        $this->line('Dados complementares: '.$plan->profileLabel());

        if ($plan->exclusionLabel() !== null) {
            $this->line('Contas fora da importação: '.$plan->exclusionLabel());
        }

        $tables = $reader->tables();

        $this->newLine();
        $this->line('Tabelas encontradas '.($single ? 'no arquivo' : 'nos arquivos').' ('.count($tables).'):');

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
    private function renderWarnings(LegacyDumpSource $reader): array
    {
        $warnings = [];
        $single = count($reader->files()) === 1;

        foreach ($reader->integrityIssues() as $issue) {
            $subject = $single ? 'o arquivo' : 'o arquivo '.basename($issue['path']);

            if ($issue['marker'] !== null) {
                $warnings[] = "{$subject} termina com uma mensagem de erro da exportação (\"{$issue['marker']}\"); os dados podem estar incompletos.";
            }

            if ($issue['unterminated']) {
                $warnings[] = "{$subject} não termina com \";\" — a exportação foi interrompida no meio de um comando.";
            }
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
        LegacyDumpSource $reader,
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

                $this->renderEntry($entry, $options->execute, $plan);
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

    private function renderEntry(LegacyImportEntry $entry, bool $executed, LegacyImportPlan $plan): void
    {
        $details = [];

        if ($entry->isSuccess()) {
            $details[] = $entry->passwordFormat->label();

            if ($entry->balanceCents > 0) {
                $details[] = 'saldo '.Money::format($entry->balanceCents);
            }

            if ($entry->birthDate !== null) {
                $details[] = 'nasc. '.$this->formatDate($entry->birthDate);
            }

            if ($entry->gender !== null) {
                $details[] = 'sexo '.$entry->gender->label();
            }

            if ($plan->hasProfile() && ! $entry->profileMatched) {
                $details[] = 'sem dados complementares em '.$plan->profileTable?->name;
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
     * Data legada em `Y-m-d` para o formato brasileiro do relatório.
     */
    private function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('d/m/Y');
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
                    'profile_source' => $plan->profileTable?->name,
                    'excluded_source' => $plan->excludeTable?->name,
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
