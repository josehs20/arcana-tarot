<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportDatabaseRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Backup\BackupCatalog;
use App\Support\Backup\BackupManager;
use App\Support\Backup\BackupResult;
use App\Support\Backup\ByteSize;
use App\Support\Backup\DatabaseImporter;
use App\Support\Backup\DatabaseImportResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * Tela "Banco de dados" da administração: mostra o backup que está no disco,
 * gera um novo e importa um dump `.sql`/`.sql.gz` por cima do banco atual.
 *
 * A importação é destrutiva (todas as tabelas são apagadas) e por isso o
 * controller grava um backup de segurança próprio antes de tocar em qualquer
 * coisa. A sessão do administrador é encerrada no fim: quem continua logado
 * depois de trocar o banco inteiro pode estar usando a identidade de outra
 * pessoa, já que os ids vêm do arquivo importado.
 */
class DatabaseController extends Controller
{
    public function index(BackupCatalog $catalog): View
    {
        Gate::authorize('access-admin-panel');

        $destination = (string) config('backup.directory');
        $safetyDirectory = (string) config('backup.safety_directory');
        $sets = $catalog->sets($destination);

        return view('admin.database.index', [
            'latest' => $sets[0] ?? null,
            'sets' => $sets,
            'safetySets' => $catalog->sets($safetyDirectory),
            'directory' => $this->displayPath($destination),
            'safetyDirectory' => $this->displayPath($safetyDirectory),
            'keep' => (int) config('backup.keep'),
            'maxUploadKb' => (int) config('backup.import_max_kb'),
            'driver' => (string) DB::connection()->getDriverName(),
            'databaseName' => (string) DB::connection()->getDatabaseName(),
        ]);
    }

    /**
     * Gera um backup completo (banco + storage), igual ao que o scheduler faz a
     * cada seis horas. Reaproveita o comando para herdar o lock, a retenção e o
     * log de falhas.
     */
    public function backup(Request $request, BackupCatalog $catalog): RedirectResponse
    {
        Gate::authorize('access-admin-panel');

        $exitCode = Artisan::call('arcana:backup');
        $output = trim(Artisan::output());
        $latest = $catalog->latest((string) config('backup.directory'));

        if (str_contains($output, 'Outro backup está em execução')) {
            return redirect()
                ->route('admin.database.index')
                ->with('warning', 'Já existe um backup em execução; aguarde a execução atual terminar.');
        }

        if ($exitCode !== 0) {
            return redirect()
                ->route('admin.database.index')
                ->with('error', 'O backup não foi gravado: '.$this->lastLine($output));
        }

        $this->recordAudit($request->user()?->id, 'database.backup_created', [
            'set' => $latest?->name,
            'bytes' => $latest?->totalBytes(),
            'tables' => $latest?->databaseTables(),
            'rows' => $latest?->databaseRows(),
        ]);

        return redirect()
            ->route('admin.database.index')
            ->with('success', sprintf(
                'Backup gerado: %s (%s).',
                $latest?->name ?? 'conjunto novo',
                ByteSize::format($latest?->totalBytes() ?? 0),
            ));
    }

    /**
     * Importa o arquivo enviado por cima do banco atual.
     *
     * A ordem é rígida: validação → backup de segurança → apagar tabelas →
     * executar o dump. Se o backup de segurança falhar, nada é apagado. Se o
     * dump falhar no meio, o conjunto de segurança é o caminho de volta.
     */
    public function import(
        ImportDatabaseRequest $request,
        BackupManager $manager,
        DatabaseImporter $importer,
    ): RedirectResponse {
        Gate::authorize('access-admin-panel');

        $file = $request->file('database_file');
        $actorId = $request->user()?->id;
        $path = $file?->getRealPath();

        if (! is_string($path) || $path === '') {
            return redirect()
                ->route('admin.database.index')
                ->with('error', 'O arquivo enviado não pôde ser lido pelo servidor. Envie novamente.');
        }

        /*
         * Um dump grande pode passar do `max_execution_time` do php.ini. O
         * tamanho do arquivo é limitado na validação (BACKUP_IMPORT_MAX_KB).
         */
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        /*
         * O cabeçalho do dump é conferido antes de qualquer coisa: um arquivo
         * de outro driver (ou ilegível) não deve gerar backup de segurança nem
         * apagar tabela alguma.
         */
        try {
            $importer->assertCompatible($path);
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.database.index')
                ->with('error', $exception->getMessage());
        }

        try {
            $safety = $manager->run((string) config('backup.safety_directory'), 2, []);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.database.index')
                ->with('error', 'A importação foi cancelada: o backup de segurança não pôde ser gravado antes de apagar o banco.');
        }

        $this->recordAudit($actorId, 'database.import_started', [
            'file' => $file?->getClientOriginalName(),
            'bytes' => $file?->getSize(),
            'safety_set' => basename($safety->directory),
        ]);

        try {
            $result = $importer->import($path);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.database.index')
                ->with('error', sprintf(
                    'A importação falhou: %s O backup de segurança está em %s; restaure-o pela linha de comando (docs/DEPLOYMENT.md, seção 10.4).',
                    $exception->getMessage(),
                    $this->displayPath($safety->directory),
                ));
        }

        $this->recordAudit($actorId, 'database.imported', [
            'file' => $file?->getClientOriginalName(),
            'bytes' => $result->bytes,
            'dropped_tables' => $result->dropped,
            'tables' => $result->tables,
            'statements' => $result->statements,
            'seconds' => round($result->seconds, 1),
            'safety_set' => basename($safety->directory),
        ]);

        return $this->endImportedSession($request, $result, $safety);
    }

    /**
     * Encerra a sessão do administrador e volta para o login.
     *
     * Os ids do banco agora são os do arquivo importado: manter a sessão atual
     * poderia autenticar o navegador como outro usuário. A mensagem de sucesso
     * fica na sessão nova (a tabela `sessions` também foi recriada).
     */
    private function endImportedSession(Request $request, DatabaseImportResult $result, BackupResult $safety): RedirectResponse
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', sprintf(
                'Banco importado: %d tabela(s) recriada(s) por %d comando(s) em %.1f s. O estado anterior está em %s. Entre novamente com um usuário do banco importado.',
                $result->tables,
                $result->statements,
                $result->seconds,
                $this->displayPath($safety->directory),
            ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(?int $actorId, string $action, array $metadata): void
    {
        /*
         * Depois da importação o banco em uso é o do arquivo: o administrador
         * que fez a operação pode não existir mais. `audit_logs.actor_id` tem
         * FK para `users`, então o evento é gravado sem ator — o id original
         * fica no metadado — em vez de estourar a importação já concluída.
         */
        $actorExists = $actorId !== null && User::query()->whereKey($actorId)->exists();

        AuditLog::query()->create([
            'actor_id' => $actorExists ? $actorId : null,
            'action' => $action,
            'metadata' => $metadata + ['actor_id' => $actorId],
        ]);
    }

    /**
     * Linha do comando que explica a falha (a última linha é o aviso sobre o
     * conjunto anterior continuar intacto).
     */
    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        foreach ($lines as $line) {
            if (str_contains($line, 'não foi gravado')) {
                return $line;
            }
        }

        return $lines === [] ? 'consulte storage/logs/laravel.log.' : end($lines);
    }

    /**
     * Caminho relativo à raiz do projeto quando possível (o operador vê
     * `storage/backups/...`, não `/var/www/html/storage/...`).
     */
    private function displayPath(string $path): string
    {
        $root = base_path().DIRECTORY_SEPARATOR;
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path(trim($path, DIRECTORY_SEPARATOR));

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
