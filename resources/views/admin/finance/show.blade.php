<x-layouts.panel variant="admin" :title="'Carteira de ' . $user->name . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Carteira de ' . $user->name"
        :subtitle="$user->email . ' — histórico de créditos e débitos.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Voltar ao financeiro</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Saldo da carteira</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <p class="stat-card__label mb-1">Créditos disponíveis</p>
                    <p class="display-5 mb-3">{{ \App\Support\Money::format($wallet?->balance_cents ?? 0) }}</p>

                    <dl class="row small mb-0">
                        <dt class="col-sm-5 text-ivory-muted fw-normal">Cliente</dt>
                        <dd class="col-sm-7">{{ $user->name }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Conta</dt>
                        <dd class="col-sm-7">{{ $user->is_active ? 'Ativa' : 'Inativa' }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Carteira criada em</dt>
                        <dd class="col-sm-7">{{ $wallet?->created_at?->format('d/m/Y H:i') ?? 'Ainda não criada' }}</dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Ajuste administrativo</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                        Toda alteração exige motivo, registra o autor e gera auditoria. Não edite o saldo diretamente.
                    </x-ui.alert>

                    <form method="POST" action="{{ route('admin.finance.wallets.adjust', $user) }}">
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ $operationId }}">

                        <div class="mb-3">
                            <label class="form-label required" for="type">Tipo</label>
                            <select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>
                                <option value="credit" @selected(old('type', 'credit') === 'credit')>Crédito</option>
                                <option value="debit" @selected(old('type') === 'debit')>Débito</option>
                            </select>
                            @error('type')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label required" for="amount_cents">Valor em centavos</label>
                            <input
                                class="form-control @error('amount_cents') is-invalid @enderror"
                                type="number"
                                id="amount_cents"
                                name="amount_cents"
                                value="{{ old('amount_cents') }}"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                required
                            >
                            <div class="form-text">Use inteiros. Ex.: 1500 = R$ 15,00.</div>
                            @error('amount_cents')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label required" for="reason">Motivo</label>
                            <textarea
                                class="form-control @error('reason') is-invalid @enderror"
                                id="reason"
                                name="reason"
                                rows="4"
                                minlength="5"
                                maxlength="500"
                                required
                            >{{ old('reason') }}</textarea>
                            @error('reason')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <x-ui.button type="submit" variant="primary" class="w-100">Registrar ajuste</x-ui.button>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card>
        <x-ui.card-header>
            <h2 class="h6 mb-0">Movimentações</h2>
        </x-ui.card-header>

        @if ($entries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhuma movimentação"
                    text="Esta carteira ainda não possui créditos ou débitos registrados."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Movimentações da carteira de {{ $user->name }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Saldo após</th>
                            <th scope="col">Origem / motivo</th>
                            <th scope="col">Autor</th>
                            <th scope="col">Idempotência</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
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
                                <td><code class="small">{{ $entry->idempotency_key }}</code></td>
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

