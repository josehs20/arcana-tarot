<x-layouts.panel variant="admin" title="Financeiro">
    <x-layouts.page-header
        title="Financeiro"
        subtitle="Acompanhe o livro-razão de créditos e ajuste carteiras com motivo e auditoria."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Saldo total em carteiras</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totalBalanceCents) }}</p>
                <p class="stat-card__hint">Projeção do livro-razão</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Créditos lançados</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totalCreditedCents) }}</p>
                <p class="stat-card__hint">Somatório histórico de entradas</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <p class="stat-card__label">Débitos lançados</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totalDebitedCents) }}</p>
                <p class="stat-card__hint">Somatório histórico de saídas</p>
            </div>
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.finance.index') }}" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="q">Buscar cliente</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome ou e-mail"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="type">Tipo</label>
                    <select class="form-select" id="type" name="type">
                        <option value="">Todos</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Valor do crédito e saldo mínimo</h2>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.modalities.index') }}">
                Preços por atendente
            </a>
        </x-ui.card-header>
        <x-ui.card-body>
            <p class="form-text mb-3">
                O preço por minuto é definido <strong>por atendente e modalidade</strong> na tela de modalidades.
                Aqui ficam apenas o valor do crédito, usado nas recargas, e o saldo mínimo exigido para aceitar uma
                consulta. Consultas já aceitas mantêm o preço congelado no aceite; alterações futuras valem apenas
                para novas consultas.
            </p>

            <div class="row g-3 mb-4">
                @foreach ($modalities as $modality)
                    <div class="col-md-4">
                        <div class="modality-price-summary">
                            <span class="modality-price-summary__label">
                                <x-ui.icon :name="$modality->icon()" width="16" height="16" />
                                {{ $modality->label() }}
                            </span>
                            <span class="modality-price-summary__value">
                                Preço por atendente
                            </span>
                            <span class="form-text">
                                Configurado em Modalidades dos atendentes
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.finance.pricing.update') }}" class="row g-3 align-items-end">
                @csrf
                @method('PATCH')

                <div class="col-md-4">
                    <label class="form-label" for="credit_unit_price_cents">Valor do crédito (centavos)</label>
                    <input
                        class="form-control @error('credit_unit_price_cents') is-invalid @enderror"
                        type="number"
                        id="credit_unit_price_cents"
                        name="credit_unit_price_cents"
                        min="1"
                        max="1000000"
                        step="1"
                        value="{{ old('credit_unit_price_cents', $pricing['credit_unit_price_cents']) }}"
                        required
                    >
                    @error('credit_unit_price_cents')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="minimum_balance_minutes">Saldo mínimo (minutos)</label>
                    <input
                        class="form-control @error('minimum_balance_minutes') is-invalid @enderror"
                        type="number"
                        id="minimum_balance_minutes"
                        name="minimum_balance_minutes"
                        min="1"
                        max="1440"
                        step="1"
                        value="{{ old('minimum_balance_minutes', $pricing['minimum_balance_minutes']) }}"
                        required
                    >
                    @error('minimum_balance_minutes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4">
                    <x-ui.button type="submit" variant="primary" size="sm">Salvar valores</x-ui.button>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Pacotes e recarga</h2>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.packages.index') }}">
                Configurar pacotes e recarga
            </a>
        </x-ui.card-header>
        <x-ui.card-body>
            <p class="form-text mb-3">
                A quantidade de créditos de cada pacote e a faixa de valores aceita na recarga da carteira são
                definidas pela administração. O valor em reais de cada pacote continua sendo calculado no servidor como
                <strong>créditos × valor do crédito</strong>.
            </p>

            <dl class="row mb-0">
                <dt class="col-sm-4 text-ivory-muted fw-normal">Valor do crédito</dt>
                <dd class="col-sm-8">
                    {{ $pricing['credit_unit_price_cents'] === null ? 'Não configurado' : \App\Support\Money::format($pricing['credit_unit_price_cents']) }}
                </dd>

                <dt class="col-sm-4 text-ivory-muted fw-normal">Pacotes no checkout</dt>
                <dd class="col-sm-8">
                    {{ $availablePackageCount }} de {{ count($creditPackages) }} disponíveis
                </dd>

                <dt class="col-sm-4 text-ivory-muted fw-normal">Faixa de recarga</dt>
                <dd class="col-sm-8 mb-0">
                    {{ $rechargeLimits['min_label'] }} a {{ $rechargeLimits['max_label'] }}
                </dd>
            </dl>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($entries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhuma movimentação encontrada"
                    text="Ajuste os filtros ou registre um ajuste na carteira de um cliente."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Livro-razão de créditos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Saldo após</th>
                            <th scope="col">Origem</th>
                            <th scope="col">Autor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <a class="fw-medium text-decoration-none" href="{{ route('admin.finance.wallets.show', $entry->wallet->user) }}">
                                        {{ $entry->wallet->user->name }}
                                    </a>
                                    <div class="form-text">{{ $entry->wallet->user->email }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? 'text-bg-success' : 'text-bg-danger' }}">
                                        {{ $entry->type->label() }}
                                    </span>
                                </td>
                                <td class="text-nowrap fw-medium">
                                    {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? '+' : '−' }}
                                    {{ \App\Support\Money::format($entry->amount_cents) }}
                                </td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($entry->balance_after_cents) }}</td>
                                <td>{{ $entry->sourceLabel() ?? '—' }}</td>
                                <td>{{ $entry->creator?->name ?? 'Sistema' }}</td>
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
