<x-layouts.panel variant="panel" title="Minhas compras">
    <x-layouts.page-header
        title="Minhas compras"
        subtitle="Produtos comprados na loja e o status oficial de cada pagamento. Os créditos entram na carteira assim que o provedor confirma a compra."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('client.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary" href="{{ route('home') }}#produtos">Ver produtos</a>
        </x-slot:actions>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Compras confirmadas</p>
                <p class="stat-card__value">{{ $paidCount }}</p>
                <p class="stat-card__hint">Pagamentos aprovados pelo provedor</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Total pago</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($paidCents) }}</p>
                <p class="stat-card__hint">Somente compras confirmadas</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Créditos recebidos</p>
                <p class="stat-card__value">{{ $paidCredits }}</p>
                <p class="stat-card__hint">Creditados na carteira</p>
            </div>
        </div>
    </div>

    <x-ui.card>
        @if ($purchases->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Você ainda não comprou produtos"
                    text="Os pacotes da loja entram na carteira como créditos depois da confirmação do pagamento."
                >
                    <a class="btn btn-primary btn-sm mt-2" href="{{ route('home') }}#produtos">Conhecer os produtos</a>
                </x-ui.empty-state>
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Suas compras de produtos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Produto</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Status</th>
                            <th scope="col">Referência</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($purchases as $purchase)
                            <tr>
                                <td class="text-nowrap">{{ $purchase->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="fw-medium">{{ $purchase->product_title }}</span>
                                    @if ($purchase->paid_at)
                                        <div class="form-text">Pago em {{ $purchase->paid_at->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td>{{ $purchase->credits_amount }}</td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($purchase->amount_cents) }}</td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$purchase->status->statusKey()"
                                        :label="$purchase->status->label()"
                                    />
                                </td>
                                <td>
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
