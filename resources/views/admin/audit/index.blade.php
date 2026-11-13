<x-layouts.panel variant="admin" title="Auditoria">
    <x-layouts.page-header
        title="Trilha de auditoria"
        subtitle="Eventos financeiros, administrativos e de catálogo. Somente leitura, com acesso restrito."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        Esta tela não mostra conteúdo de conversas. O acesso excepcional a mensagens não faz parte do painel do MVP.
        Metadados são exibidos escapados e não incluem senhas, tokens ou payloads de pagamento.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.audit.index') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="q">Buscar</label>
                    <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Ação, ator ou assunto" maxlength="100">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="action">Ação</label>
                    <select class="form-select" id="action" name="action">
                        <option value="">Todas</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="date_from">De</label>
                    <input class="form-control" type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="date_to">Até</label>
                    <input class="form-control" type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.audit.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($logs->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum evento encontrado"
                    text="Ajuste os filtros para localizar registros de auditoria."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Eventos de auditoria administrativa</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Ação</th>
                            <th scope="col">Ator</th>
                            <th scope="col">Assunto</th>
                            <th scope="col">Metadados</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                <td><code>{{ $log->action }}</code></td>
                                <td>
                                    {{ $log->actor?->name ?? 'Sistema' }}
                                    @if ($log->actor)
                                        <div class="form-text">{{ $log->actor->email }}</div>
                                    @endif
                                </td>
                                <td>
                                    {{ $log->subject_type ? class_basename($log->subject_type) : '—' }}
                                    @if ($log->subject_id !== null)
                                        <div class="form-text">#{{ $log->subject_id }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (filled($log->metadata))
                                        <details>
                                            <summary class="small">Ver metadados</summary>
                                            <pre class="small text-wrap mt-2 mb-0">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @else
                                        <span class="text-ivory-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $logs->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
