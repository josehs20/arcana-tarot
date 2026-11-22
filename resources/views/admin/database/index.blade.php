<x-layouts.panel variant="admin" title="Banco de dados">
    <x-layouts.page-header
        title="Banco de dados"
        subtitle="Backup do banco e do storage, com a opção de importar um dump .sql ou .sql.gz por cima do banco atual."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>

            <form method="POST" action="{{ route('admin.database.backup') }}" class="d-inline">
                @csrf
                <x-ui.button type="submit" variant="primary" size="sm">Fazer backup agora</x-ui.button>
            </form>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O scheduler grava um backup completo a cada seis horas em <code>{{ $directory }}</code>,
        mantendo <strong>{{ $keep }}</strong> conjunto(s). O botão acima roda a mesma rotina na hora.
        Este diretório <strong>não substitui a cópia off-site</strong>: arquivo no mesmo disco do banco
        não protege contra perda do disco nem contra ransomware.
    </x-ui.alert>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Backup mais recente</h2>
                </x-ui.card-header>

                @if ($latest === null)
                    <x-ui.card-body>
                        <x-ui.empty-state
                            icon="◇"
                            title="Nenhum backup no diretório"
                            text="Use “Fazer backup agora” ou aguarde a próxima execução do scheduler (a cada seis horas)."
                        />
                    </x-ui.card-body>
                @else
                    <x-ui.card-body>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Conjunto</dt>
                            <dd class="col-sm-8"><code>{{ $latest->name }}</code></dd>

                            <dt class="col-sm-4">Gerado em</dt>
                            <dd class="col-sm-8">
                                {{ $latest->createdAt?->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}
                            </dd>

                            <dt class="col-sm-4">Banco</dt>
                            <dd class="col-sm-8">
                                {{ $latest->databaseTables() ?? '—' }} tabela(s) ·
                                {{ $latest->databaseRows() ?? '—' }} linha(s) ·
                                {{ \App\Support\Backup\ByteSize::format($latest->databaseBytes()) }}
                                <div class="form-text">
                                    {{ $latest->databaseDriver() ?? 'driver não registrado' }} /
                                    {{ $latest->databaseName() ?? 'banco não registrado' }}
                                </div>
                            </dd>

                            <dt class="col-sm-4">Storage</dt>
                            <dd class="col-sm-8">
                                @if ($latest->hasFiles)
                                    {{ $latest->filesCount() ?? '—' }} arquivo(s) ·
                                    {{ \App\Support\Backup\ByteSize::format($latest->filesBytes()) }}
                                @else
                                    <span class="text-ivory-muted">Conjunto sem arquivos de storage (backup só do banco).</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Tamanho total</dt>
                            <dd class="col-sm-8">{{ \App\Support\Backup\ByteSize::format($latest->totalBytes()) }}</dd>

                            <dt class="col-sm-4">Dump do banco</dt>
                            <dd class="col-sm-8">
                                @if ($latest->hasDatabase)
                                    <code>{{ $latest->name }}/database.sql.gz</code>
                                    @if ($latest->databaseSha256())
                                        <div class="form-text text-break">sha256 {{ $latest->databaseSha256() }}</div>
                                    @endif
                                @else
                                    <span class="text-warning">Arquivo ausente; este conjunto não serve para restauração.</span>
                                @endif
                            </dd>
                        </dl>
                    </x-ui.card-body>
                @endif
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Banco atual</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row mb-0">
                        <dt class="col-5">Conexão</dt>
                        <dd class="col-7"><code>{{ $driver }}</code></dd>

                        <dt class="col-5">Banco</dt>
                        <dd class="col-7"><code>{{ $databaseName }}</code></dd>

                        <dt class="col-5">Retenção</dt>
                        <dd class="col-7">{{ $keep }} conjunto(s)</dd>

                        <dt class="col-5">Segurança</dt>
                        <dd class="col-7">
                            <code class="small">{{ $safetyDirectory }}</code>
                            <div class="form-text">Gravado antes de cada importação.</div>
                        </dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Importar banco</h2>
        </x-ui.card-header>
        <x-ui.card-body>
            <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                <strong>O banco atual será apagado.</strong>
                Todas as tabelas de <code>{{ $databaseName }}</code> são removidas e recriadas a partir do
                arquivo enviado; registros, carteiras, consultas e usuários que não estiverem no dump deixam
                de existir. Um backup de segurança é gravado em <code>{{ $safetyDirectory }}</code> antes da
                remoção, e você será desconectado ao final.
            </x-ui.alert>

            <form
                method="POST"
                action="{{ route('admin.database.import') }}"
                enctype="multipart/form-data"
                class="row g-3 align-items-end"
                data-confirm="ISSO APAGA TODAS AS TABELAS DO BANCO ATUAL ({{ $databaseName }}) e importa o arquivo enviado. O backup de segurança será gravado antes, mas a operação não pode ser desfeita pelo painel. Continuar?"
            >
                @csrf

                <div class="col-md-8">
                    <label class="form-label required" for="database_file">Arquivo .sql ou .sql.gz</label>
                    <input
                        type="file"
                        class="form-control @error('database_file') is-invalid @enderror"
                        id="database_file"
                        name="database_file"
                        accept=".sql,.gz,application/sql,application/gzip"
                        required
                    >
                    @error('database_file')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <div class="form-text">
                        Até {{ $maxUploadKb }} KB (limite de upload do servidor). O dump precisa ter
                        <code>CREATE TABLE</code> e <code>INSERT</code>s, como o <code>database.sql.gz</code>
                        gerado pelo backup; arquivos maiores devem ser restaurados pela linha de comando
                        (docs/DEPLOYMENT.md, seção 10.4).
                    </div>
                </div>

                <div class="col-md-4">
                    <x-ui.button type="submit" variant="danger" block>Apagar banco e importar</x-ui.button>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            class="form-check-input @error('confirmacao') is-invalid @enderror"
                            id="confirmacao"
                            name="confirmacao"
                            value="1"
                            required
                        >
                        <label class="form-check-label" for="confirmacao">
                            Entendo que as tabelas atuais serão apagadas e que a sessão será encerrada.
                        </label>
                        @error('confirmacao')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card class="mb-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Backups no servidor</h2>
        </x-ui.card-header>
        <x-ui.card-body>
            @include('admin.database.partials.sets-table', [
                'sets' => $sets,
                'emptyText' => 'Nenhum backup em ' . $directory . ' ainda.',
            ])
        </x-ui.card-body>
    </x-ui.card>

    @if ($safetySets !== [])
        <x-ui.card>
            <x-ui.card-header>
                <h2 class="h6 mb-0">Backups de segurança (antes de importar)</h2>
            </x-ui.card-header>
            <x-ui.card-body>
                @include('admin.database.partials.sets-table', ['sets' => $safetySets])
            </x-ui.card-body>
        </x-ui.card>
    @endif
</x-layouts.panel>
