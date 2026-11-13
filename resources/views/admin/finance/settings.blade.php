<x-layouts.panel variant="admin" title="Pacotes e recarga">
    <x-layouts.page-header
        title="Pacotes e recarga"
        subtitle="Defina o que o cliente vê no checkout de créditos: catálogo de pacotes e faixa de valores aceita na recarga da carteira."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Voltar ao financeiro</a>
        </x-slot>
    </x-layouts.page-header>

    @php
        $availablePackages = collect($creditPackages)->where('available', true)->count();
    @endphp

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <x-ui.stat-card
                label="Valor do crédito"
                :value="$creditUnitPriceLabel ?? 'Não configurado'"
                hint="Definido em Financeiro, no cartão de valor do crédito"
                icon="credit-card"
            />
        </div>
        <div class="col-md-4">
            <x-ui.stat-card
                label="Faixa de recarga"
                :value="$rechargeLimits['min_label'].' a '.$rechargeLimits['max_label']"
                hint="Mínimo e máximo por recarga"
                icon="wallet"
                tone="gold"
            />
        </div>
        <div class="col-md-4">
            <x-ui.stat-card
                label="Pacotes no checkout"
                :value="$availablePackages.' de '.count($creditPackages)"
                hint="Pacotes fora da faixa não aparecem"
                icon="layers"
            />
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Faixa de recarga da carteira</h2>
            <span class="badge text-bg-secondary">
                {{ $rechargeLimits['min_label'] }} a {{ $rechargeLimits['max_label'] }}
            </span>
        </x-ui.card-header>
        <x-ui.card-body>
            <p class="form-text mb-3">
                Vale para toda recarga de carteira e também para os pacotes: um pacote cujo total fique fora da faixa
                deixa de ser oferecido no checkout. O mínimo precisa cobrir ao menos 1 crédito, e o máximo não pode ser
                menor que o mínimo.
            </p>

            <form
                method="POST"
                action="{{ route('admin.finance.packages.limits.update') }}"
                class="row g-3 align-items-end"
            >
                @csrf
                @method('PATCH')

                <div class="col-md-4">
                    <label class="form-label required" for="min_amount_reais">Valor mínimo da recarga (R$)</label>
                    <input
                        class="form-control @error('min_amount_cents') is-invalid @enderror"
                        type="number"
                        id="min_amount_reais"
                        name="min_amount_reais"
                        value="{{ old('min_amount_reais', $rechargeLimits['min_input']) }}"
                        min="0.01"
                        step="0.01"
                        inputmode="decimal"
                        required
                    >
                    @error('min_amount_cents')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="max_amount_reais">Valor máximo da recarga (R$)</label>
                    <input
                        class="form-control @error('max_amount_cents') is-invalid @enderror"
                        type="number"
                        id="max_amount_reais"
                        name="max_amount_reais"
                        value="{{ old('max_amount_reais', $rechargeLimits['max_input']) }}"
                        min="0.01"
                        step="0.01"
                        inputmode="decimal"
                        required
                    >
                    @error('max_amount_cents')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4">
                    <x-ui.button type="submit" variant="primary" size="sm">Salvar faixa</x-ui.button>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    @include('admin.finance.partials.packages-card')
</x-layouts.panel>
