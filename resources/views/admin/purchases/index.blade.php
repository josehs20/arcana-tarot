<x-layouts.panel variant="admin" title="Vendas">
    <x-layouts.page-header
        title="Vendas"
        subtitle="Compras de produtos da vitrine. O status é o oficial do provedor de pagamento; esta tela é somente leitura."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.products.index') }}">Produtos</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Financeiro</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-card">
                <p class="stat-card__label">Vendas confirmadas</p>
                <p class="stat-card__value">{{ $confirmedCount }}</p>
                <p class="stat-card__hint">No filtro atual</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <p class="stat-card__label">Valor confirmado</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($confirmedCents) }}</p>
                <p class="stat-card__hint">Somente compras com status pago</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O status é alterado exclusivamente pelo webhook autenticado do provedor. Estornos não removem créditos automaticamente;
        a auditoria sinaliza a análise manual.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.purchases.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Cliente, e-mail, produto ou referência"
                        maxlength="120"
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
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.purchases.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($purchases->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◈"
                    title="Nenhuma venda encontrada"
                    text="Ajuste os filtros ou aguarde a primeira compra de produto na home."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Lista administrativa de vendas de produtos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Criado em</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Produto</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Status</th>
                            <th scope="col">Pago em</th>
                            <th scope="col">Referência</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($purchases as $purchase)
                            <tr>
                                <td class="text-nowrap">{{ $purchase->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="fw-medium">{{ $purchase->user->name }}</span>
                                    <div class="form-text">{{ $purchase->user->email }}</div>
                                </td>
                                <td>
                                    <span class="fw-medium">{{ $purchase->product_title }}</span>
                                    <div class="form-text">
                                        {{ $purchase->product?->title ?? 'Produto removido' }}
                                    </div>
                                </td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($purchase->amount_cents) }}</td>
                                <td>{{ $purchase->credits_amount }}</td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$purchase->status->statusKey()"
                                        :label="$purchase->status->label()"
                                    />
                                </td>
                                <td class="text-nowrap">{{ $purchase->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="d-block small">{{ $purchase->provider ?? '—' }}</span>
                                    <span class="form-text">{{ $purchase->external_reference }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>{{ $purchases->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

