<?php

use App\Support\Backup\BackupCatalog;

/**
 * Backup periódico gerado pela própria aplicação (`php artisan arcana:backup`).
 *
 * O comando é agendado a cada seis horas em `routes/console.php` e grava, em um
 * único diretório por execução, o dump lógico do banco e um arquivo com o
 * conteúdo de `storage/app` (imagens do catálogo, snapshots de chat e anexos).
 * A retenção padrão é de um conjunto: o backup anterior só é apagado depois que
 * o novo está completo, então uma falha nunca deixa o servidor sem backup.
 *
 * O mesmo diretório é a origem da tela "Banco de dados" do painel, e os limites
 * de importação (`import_max_kb`) também estão aqui.
 *
 * O backup não substitui a cópia off-site descrita em docs/DEPLOYMENT.md:
 * arquivo no mesmo disco do banco não protege contra perda do disco.
 */
$backupDirectory = trim((string) env('BACKUP_DIRECTORY', ''));
$resolvedDirectory = $backupDirectory !== '' ? $backupDirectory : storage_path('backups');
$safetyDirectory = trim((string) env('BACKUP_SAFETY_DIRECTORY', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Diretório de destino
    |--------------------------------------------------------------------------
    |
    | Caminho relativo é resolvido a partir da raiz do projeto; use um caminho
    | absoluto para gravar em outro volume (ex.: `/var/backups/arcana`).
    | Precisa ser gravável pelo usuário do processo (`app`/`scheduler`, uid
    | 1000 no Compose). O padrão fica fora de `storage/app`, portanto o backup
    | nunca inclui a si mesmo.
    |
    */

    'directory' => $resolvedDirectory,

    /*
    |--------------------------------------------------------------------------
    | Backup de segurança antes de importar um banco
    |--------------------------------------------------------------------------
    |
    | A importação pelo painel grava um conjunto neste diretório antes de apagar
    | as tabelas. Fica separado do destino principal para não competir com a
    | retenção do backup automático (que mantém um conjunto a cada seis horas).
    | O padrão é `<diretório>/pre-import`.
    |
    */

    'safety_directory' => $safetyDirectory !== ''
        ? $safetyDirectory
        : rtrim($resolvedDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.BackupCatalog::SAFETY_SUBDIRECTORY,

    /*
    |--------------------------------------------------------------------------
    | Retenção
    |--------------------------------------------------------------------------
    |
    | Quantos conjuntos manter no diretório. Com 1 (padrão) existe no máximo um
    | backup concluído: durante a execução o novo conjunto é gravado ao lado do
    | anterior e a limpeza remove o antigo no fim. Só diretórios com o prefixo
    | `arcana-backup-` são candidatos à remoção; o que o operador copiar para
    | dentro do diretório é preservado.
    |
    */

    'keep' => max(1, (int) env('BACKUP_KEEP', 1)),

    /*
    |--------------------------------------------------------------------------
    | Limite do arquivo de importação
    |--------------------------------------------------------------------------
    |
    | Tamanho máximo do `.sql`/`.sql.gz` aceito no painel, em KB. O padrão de
    | 32768 (32 MB) acompanha o `upload_max_filesize` de `docker/php/uploads.ini`;
    | em hospedagem compartilhada ajuste conforme o limite do provedor. Dumps
    | maiores precisam ser restaurados por linha de comando (seção 10.4 do
    | docs/DEPLOYMENT.md) ou com o arquivo reduzido.
    |
    */

    'import_max_kb' => max(1, (int) env('BACKUP_IMPORT_MAX_KB', 32768)),

    /*
    |--------------------------------------------------------------------------
    | Caminhos incluídos no arquivo de storage
    |--------------------------------------------------------------------------
    |
    | Relativos à raiz do projeto, dentro do arquivo `files.tar.gz`. Ficam de
    | fora `storage/backups` (o próprio backup), `storage/logs` (rotativo e sem
    | valor de recuperação) e o `.env`, que guarda segredos e deve ficar em
    | cofre separado — backup de dados nunca leva credencial junto.
    |
    */

    'storage_paths' => [
        'storage/app',
    ],

];
