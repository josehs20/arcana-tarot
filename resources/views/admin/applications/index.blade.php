<x-layouts.panel variant="admin" title="Candidaturas | Arcana Tarot">
    <x-layouts.page-header
        title="Candidaturas"
        :subtitle="'Recebidas pelo formulário Trabalhe conosco. Pendentes: ' . $pendingCount . '.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.applications.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome, e-mail ou telefone"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                {{ match ($status->value) {
                                    'pending' => 'Pendente',
                                    'under_review' => 'Em análise',
                                    'approved' => 'Aprovado',
                                    'rejected' => 'Rejeitado',
                                    default => $status->value,
                                } }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="date_from">Enviada de</label>
                    <input
                        class="form-control"
                        type="date"
                        id="date_from"
                        name="date_from"
                        value="{{ $filters['date_from'] ?? '' }}"
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="date_to">Enviada até</label>
                    <input
                        class="form-control"
                        type="date"
                        id="date_to"
                        name="date_to"
                        value="{{ $filters['date_to'] ?? '' }}"
                    >
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.applications.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($applications->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhuma candidatura encontrada"
                    text="Ajuste os filtros ou aguarde novas candidaturas pelo site público."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover application-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Candidato</th>
                            <th scope="col">Local</th>
                            <th scope="col">Status</th>
                            <th scope="col">Enviada em</th>
                            <th scope="col">Revisor</th>
                            <th scope="col" class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($applications as $application)
                            <tr>
                                <td>
                                    <a class="fw-medium" href="{{ route('admin.applications.show', $application) }}">
                                        {{ $application->name }}
                                    </a>
                                    <div class="form-text">{{ $application->email }}</div>
                                </td>
                                <td>{{ $application->location() ?? '—' }}</td>
                                <td>
                                    <x-ui.status-badge :status="$application->status->value" />
                                </td>
                                <td>
                                    {{ $application->submitted_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td>{{ $application->reviewer?->name ?? '—' }}</td>
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.applications.show', $application) }}">
                                        Ver detalhes
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $applications->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
