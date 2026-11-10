<x-layouts.panel variant="admin" title="Bônus de cadastro | Arcana Tarot">
    <x-layouts.page-header
        title="Bônus de primeiro acesso"
        subtitle="Créditos presenteados uma única vez a cada cliente que confirma o e-mail do cadastro ou entra pela primeira vez com o Google."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Voltar ao financeiro</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <x-ui.stat-card
                label="Situação"
                :value="$bonus['enabled'] ? 'Ativo' : 'Inativo'"
                :hint="$bonus['enabled'] ? 'Novos clientes recebem o bônus' : 'Nenhum crédito está sendo concedido'"
                icon="sparkles"
                :tone="$bonus['enabled'] ? 'gold' : 'default'"
            />
        </div>
        <div class="col-md-6 col-xl-3">
            <x-ui.stat-card
                label="Créditos por cliente"
                :value="$bonus['credits']"
                hint="Concedidos apenas no primeiro acesso"
                icon="wallet"
            />
        </div>
        <div class="col-md-6 col-xl-3">
            <x-ui.stat-card
                label="Valor do bônus"
                :value="$bonusAmountLabel ?? 'Não calculado'"
                :hint="$creditUnitPriceLabel === null
                    ? 'Defina o valor do crédito no financeiro'
                    : $bonus['credits'].' × '.$creditUnitPriceLabel"
                icon="credit-card"
            />
        </div>
        <div class="col-md-6 col-xl-3">
            <x-ui.stat-card
                label="Já concedido"
                :value="$grantedAmountLabel"
                :hint="$bonus['granted_count'].' concessão(ões) registradas'"
                icon="activity"
            />
        </div>
    </div>

    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Configuração do bônus</h2>
            <span class="badge {{ $bonus['enabled'] ? 'text-bg-success' : 'text-bg-secondary' }}">
                {{ $bonus['enabled'] ? 'Ativo' : 'Inativo' }}
            </span>
        </x-ui.card-header>
        <x-ui.card-body>
            @error('enabled')
                <x-ui.alert type="danger" :dismissible="false" class="mb-3">{{ $message }}</x-ui.alert>
            @enderror

            <form method="POST" action="{{ route('admin.finance.bonus.update') }}" class="row g-3 align-items-end">
                @csrf
                @method('PATCH')

                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="enabled"
                            name="enabled"
                            value="1"
                            @checked(old('enabled', $bonus['enabled']))
                        >
                        <label class="form-check-label" for="enabled">Ativar bônus de primeiro acesso</label>
                    </div>
                    <div class="form-text">Desligado, nenhum cliente recebe créditos ao se cadastrar.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="credits">Créditos por cliente</label>
                    <input
                        class="form-control @error('credits') is-invalid @enderror"
                        type="number"
                        id="credits"
                        name="credits"
                        value="{{ old('credits', $bonus['credits']) }}"
                        min="0"
                        max="{{ $maxCredits }}"
                        step="1"
                        inputmode="numeric"
                        required
                    >
                    @error('credits')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4">
                    <x-ui.button type="submit" variant="primary" size="sm">Salvar bônus</x-ui.button>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>
</x-layouts.panel>
