<x-layouts.panel variant="admin" :title="'Comissão de ' . $attendant->name . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Comissão de ' . ($profile->display_name ?: $attendant->name)"
        :subtitle="$attendant->email . ' — configuração da taxa e histórico do razão.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.commissions.index', ['attendant_id' => $attendant->id]) }}">Ver no relatório</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Taxa atual</p>
                <p class="stat-card__value">{{ \App\Support\Rate::formatBps($profile->commission_rate_bps) }}</p>
                <p class="stat-card__hint">Aplicada apenas a novas consultas</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Receita de serviço</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['gross_cents']) }}</p>
                <p class="stat-card__hint">Base histórica das comissões</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Comissão acumulada</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($summary->totalCents) }}</p>
                <p class="stat-card__hint">{{ \App\Support\Money::format($summary->monthCents) }} neste mês</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Estornos</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totals['reversed_cents']) }}</p>
                <p class="stat-card__hint">Lançamentos separados e auditados</p>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Configuração da taxa</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <form method="POST" action="{{ route('admin.commissions.rate.update', $attendant) }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label class="form-label required" for="commission_rate">Percentual de comissão</label>
                            <div class="input-group">
                                <input
                                    class="form-control @error('commission_rate') is-invalid @enderror"
                                    type="number"
                                    id="commission_rate"
                                    name="commission_rate"
                                    value="{{ old('commission_rate', \App\Support\Rate::inputValue($profile->commission_rate_bps)) }}"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    required
                                >
                                <span class="input-group-text">%</span>
                                @error('commission_rate')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <p class="form-text mb-0">Entre 0,00% e 100,00%, com até duas casas decimais.</p>
                        </div>

                        <x-ui.button type="submit" variant="primary">Salvar taxa</x-ui.button>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Como o valor é calculado</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <p class="small mb-2">
                        A taxa é congelada no aceite de cada consulta. Alterações futuras não recalculam consultas
                        já iniciadas nem lançamentos antigos.
                    </p>
                    <p class="small mb-2">
                        A base é o valor efetivamente debitado do cliente em centavos, usando o preço do crédito
                        congelado na consulta. Recargas, saldo restante e quantidade bruta de créditos não são usados.
                    </p>
                    <p class="small mb-0">
                        O total é uma participação interna na receita do serviço. Não há pagamento, transferência
                        ou saque neste MVP.
                    </p>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card>
        <x-ui.card-header class="d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Histórico de comissões</h2>
            <span class="form-text">Fonte única: razão de comissões</span>
        </x-ui.card-header>

        @if ($entries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum lançamento"
                    text="As comissões aparecem após o encerramento de uma consulta iniciada e cobrada."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Histórico de comissões de {{ $attendant->name }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Consulta</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Taxa aplicada</th>
                            <th scope="col">Base</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Motivo / autor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
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
                                    <span class="form-text">{{ $entry->actor_id ? 'Administração' : 'Sistema' }}</span>
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

