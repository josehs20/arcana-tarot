<x-layouts.panel variant="admin" title="Pagamentos | Arcana Tarot">
    <x-layouts.page-header
        title="Pagamentos"
        subtitle="Recargas PIX registradas, com filtros e totais calculados a partir do estado oficial."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Financeiro</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-card">
                <p class="stat-card__label">Recargas confirmadas</p>
                <p class="stat-card__value">{{ $confirmedCount }}</p>
                <p class="stat-card__hint">No filtro atual</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <p class="stat-card__label">Valor confirmado</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($confirmedCents) }}</p>
                <p class="stat-card__hint">Somente pedidos com status confirmado</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O conteúdo PIX, o payload do webhook e credenciais do provedor não são exibidos nesta tela.
        A confirmação de saldo é feita exclusivamente pelo webhook autenticado.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.payments.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Cliente, e-mail ou referência"
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
                    <label class="form-label" for="date_from">De</label>
                    <input class="form-control" type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="date_to">Até</label>
                    <input class="form-control" type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.payments.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($payments->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◈"
                    title="Nenhum pagamento encontrado"
                    text="Ajuste os filtros ou aguarde a criação de uma recarga por um cliente."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Lista administrativa de pagamentos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Criado em</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Status</th>
                            <th scope="col">Pago em</th>
                            <th scope="col">Referência</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="text-nowrap">{{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="fw-medium">{{ $payment->user->name }}</span>
                                    <div class="form-text">{{ $payment->user->email }}</div>
                                </td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($payment->amount_cents) }}</td>
                                <td>{{ $payment->credits }}</td>
                                <td><x-ui.status-badge :status="$payment->status->value" :label="$payment->status->label()" /></td>
                                <td class="text-nowrap">{{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="d-block small">{{ $payment->provider }}</span>
                                    <span class="form-text">{{ $payment->external_reference }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $payments->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

