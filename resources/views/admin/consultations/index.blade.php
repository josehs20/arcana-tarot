<x-layouts.panel variant="admin" title="Consultas">
    <x-layouts.page-header
        title="Consultas"
        subtitle="Acompanhe todas as solicitações e atendimentos da plataforma."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.consultations.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome ou e-mail do cliente ou atendente"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="modality">Modalidade</label>
                    <select class="form-select" id="modality" name="modality">
                        <option value="">Todas</option>
                        @foreach ($modalities as $modality)
                            <option value="{{ $modality->value }}" @selected(($filters['modality'] ?? '') === $modality->value)>
                                {{ $modality->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="incidents">Ocorrências</label>
                    <select class="form-select" id="incidents" name="incidents">
                        <option value="">Todas</option>
                        <option value="with" @selected(($filters['incidents'] ?? '') === 'with')>Com ocorrência</option>
                        <option value="without" @selected(($filters['incidents'] ?? '') === 'without')>Sem ocorrência</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="incident_type">Tipo de dado</label>
                    <select class="form-select" id="incident_type" name="incident_type">
                        <option value="">Todos</option>
                        @foreach ($incidentCategories as $category)
                            <option value="{{ $category->value }}" @selected(($filters['incident_type'] ?? '') === $category->value)>
                                {{ $category->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($consultations->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhuma consulta encontrada"
                    text="Ajuste os filtros para localizar atendimentos registrados."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col">Atendente</th>
                            <th scope="col">Modalidade</th>
                            <th scope="col">Status</th>
                            <th scope="col">Preço/min</th>
                            <th scope="col">Duração</th>
                            <th scope="col">Total</th>
                            <th scope="col">Comissão</th>
                            <th scope="col">Ocorrências</th>
                            <th scope="col">Solicitada em</th>
                            <th scope="col">Encerrada em</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($consultations as $consultation)
                            <tr>
                                <td>
                                    <span class="fw-medium">{{ $consultation->client->name }}</span>
                                    <div class="form-text">{{ $consultation->client->email }}</div>
                                </td>
                                <td>
                                    <span class="fw-medium">{{ $consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name }}</span>
                                    <div class="form-text">{{ $consultation->attendant->email }}</div>
                                </td>
                                <td>
                                    <x-ui.badge variant="gold">{{ $consultation->modalityLabel() }}</x-ui.badge>
                                </td>
                                <td>
                                    <x-ui.status-badge :status="$consultation->status->value" :label="$consultation->status->label()" />
                                </td>
                                <td class="text-nowrap">
                                    {{ $consultation->pricePerMinuteLabel() ?? '—' }}
                                    <div class="form-text">{{ $consultation->modalityLabel() }}</div>
                                </td>
                                <td class="text-nowrap">{{ $consultation->durationLabel() ?? '—' }}</td>
                                <td class="text-nowrap">{{ $consultation->totalChargedLabel() }}</td>
                                <td class="text-nowrap">
                                    {{ \App\Support\Money::format($consultation->commissionNetCents()) }}
                                    <div class="form-text">{{ $consultation->commission_rate_bps !== null ? \App\Support\Rate::formatBps($consultation->commission_rate_bps) : '—' }}</div>
                                </td>
                                <td class="text-nowrap">
                                    @if (($consultation->privacy_incidents_count ?? 0) > 0)
                                        <x-ui.badge :variant="($consultation->open_privacy_incidents_count ?? 0) > 0 ? 'warning' : 'secondary'">
                                            {{ $consultation->privacy_incidents_count }}
                                            @if (($consultation->open_privacy_incidents_count ?? 0) > 0)
                                                · {{ $consultation->open_privacy_incidents_count }} em aberto
                                            @endif
                                        </x-ui.badge>
                                    @else
                                        <span class="text-ivory-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $consultation->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-nowrap">{{ $consultation->ended_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.show', $consultation) }}">
                                        Visualizar
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $consultations->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

