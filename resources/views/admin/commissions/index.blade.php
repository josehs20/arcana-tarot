<x-layouts.panel variant="admin" title="Comissões">
    <x-layouts.page-header
        title="Comissões"
        subtitle="Taxas por atendente, razão imutável e comparação entre receita de serviço e comissões."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    @error('reason')
        <x-ui.alert type="danger" :dismissible="false">{{ $message }}</x-ui.alert>
    @enderror

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Receita de serviço</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['gross_cents']) }}</p>
                <p class="stat-card__hint">Bases das comissões no filtro</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Comissões</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['commission_cents']) }}</p>
                <p class="stat-card__hint">Estornos subtraem do total</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Receita após comissões</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['net_cents']) }}</p>
                <p class="stat-card__hint">Não representa lucro líquido</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Estornos</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['reversed_cents']) }}</p>
                <p class="stat-card__hint">Lançamentos de reversão no filtro</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="warning" :dismissible="false" class="mb-4">
        Receita após comissões não é lucro líquido: impostos, custos operacionais e taxas de pagamento não estão modelados.
        Os valores abaixo vêm exclusivamente do razão de comissões e não geram pagamento ou saque.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.commissions.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="attendant_id">Atendente</label>
                    <select class="form-select" id="attendant_id" name="attendant_id">
                        <option value="">Todos</option>
                        @foreach ($attendants as $attendant)
                            <option value="{{ $attendant->id }}" @selected((string) ($filters['attendant_id'] ?? '') === (string) $attendant->id)>
                                {{ $attendant->attendantProfile?->display_name ?? $attendant->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="consultation_id">Consulta</label>
                    <input
                        class="form-control"
                        type="number"
                        id="consultation_id"
                        name="consultation_id"
                        min="1"
                        value="{{ $filters['consultation_id'] ?? '' }}"
                        placeholder="ID"
                    >
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
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.commissions.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($entries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum lançamento de comissão"
                    text="Comissões são geradas quando uma consulta iniciada e cobrada é encerrada ou interrompida."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Razão de comissões dos atendentes</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Atendente</th>
                            <th scope="col">Consulta</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Taxa</th>
                            <th scope="col">Base</th>
                            <th scope="col">Comissão</th>
                            <th scope="col">Motivo / autor</th>
                            <th scope="col" class="text-end">Estorno</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <a class="fw-medium text-decoration-none" href="{{ route('admin.commissions.attendant', $entry->attendant) }}">
                                        {{ $entry->attendant->attendantProfile?->display_name ?? $entry->attendant->name }}
                                    </a>
                                </td>
                                <td>
                                    @if ($entry->consultation)
                                        <a class="text-decoration-none" href="{{ route('consultations.show', $entry->consultation) }}">#{{ $entry->consultation_id }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ match ($entry->type) {
                                        \App\Enums\CommissionEntryType::Accrual => 'text-bg-success',
                                        \App\Enums\CommissionEntryType::Reversal => 'text-bg-danger',
                                        default => 'text-bg-secondary',
                                    } }}">
                                        {{ $entry->type->label() }}
                                    </span>
                                </td>
                                <td>{{ \App\Support\Rate::formatBps($entry->commission_rate_bps) }}</td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($entry->base_amount_cents) }}</td>
                                <td class="text-nowrap {{ $entry->signedAmountCents() < 0 ? 'text-danger' : '' }}">
                                    {{ $entry->signedAmountCents() < 0 ? '−' : '+' }}
                                    {{ \App\Support\Money::format(abs($entry->signedAmountCents())) }}
                                </td>
                                <td>
                                    <span class="d-block small">{{ $entry->reason ?? '—' }}</span>
                                    <span class="form-text">{{ $entry->actor?->name ?? 'Sistema' }}</span>
                                </td>
                                <td class="text-end">
                                    @if ($entry->reversal)
                                        <x-ui.status-badge status="inactive" label="Estornado" />
                                    @elseif ($entry->canBeReversed())
                                        @can('reverse', $entry)
                                            <form method="POST" action="{{ route('admin.commissions.reverse', $entry) }}" class="d-flex flex-column flex-md-row gap-1 justify-content-end">
                                                @csrf
                                                <input
                                                    class="form-control form-control-sm"
                                                    type="text"
                                                    name="reason"
                                                    placeholder="Motivo"
                                                    minlength="5"
                                                    maxlength="500"
                                                    required
                                                    aria-label="Motivo do estorno da comissão #{{ $entry->id }}"
                                                >
                                                <x-ui.button type="submit" variant="ghost" size="sm" class="text-danger text-nowrap">Estornar</x-ui.button>
                                            </form>
                                        @endcan
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
                {{ $entries->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
