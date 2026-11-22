{{--
    Tabela de conjuntos de backup (`arcana-backup-*`) lidos do disco.

    Uso:
        @include('admin.database.partials.sets-table', ['sets' => $sets, 'emptyText' => '...'])
--}}
@php
    $emptyText = $emptyText ?? 'Nenhum conjunto de backup no diretório.';
@endphp

@if ($sets === [])
    <p class="text-ivory-muted mb-0">{{ $emptyText }}</p>
@else
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">Conjuntos de backup gravados no servidor</caption>
            <thead>
                <tr>
                    <th scope="col">Conjunto</th>
                    <th scope="col">Gerado em</th>
                    <th scope="col">Banco</th>
                    <th scope="col">Storage</th>
                    <th scope="col" class="text-end">Tamanho</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sets as $set)
                    <tr>
                        <td>
                            <code class="small">{{ $set->name }}</code>
                            @unless ($set->hasDatabase)
                                <div class="form-text text-warning">Sem dump do banco neste conjunto.</div>
                            @endunless
                        </td>
                        <td class="text-nowrap">
                            {{ $set->createdAt?->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—' }}
                        </td>
                        <td class="text-nowrap">
                            @if ($set->manifest === null)
                                <span class="text-ivory-muted">Manifesto ausente</span>
                            @else
                                {{ $set->databaseTables() ?? '—' }} tabela(s)
                                <div class="form-text">{{ $set->databaseRows() ?? '—' }} linha(s) · {{ $set->databaseDriver() ?? '—' }}</div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if ($set->hasFiles)
                                {{ $set->filesCount() ?? '—' }} arquivo(s)
                                <div class="form-text">{{ \App\Support\Backup\ByteSize::format($set->filesBytes()) }}</div>
                            @else
                                <span class="text-ivory-muted">Sem arquivos</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">{{ \App\Support\Backup\ByteSize::format($set->totalBytes()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
