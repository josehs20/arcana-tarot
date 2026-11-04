<x-layouts.panel variant="panel" title="Meu histórico | Arcana Tarot">
    <x-layouts.page-header
        title="Meu histórico"
        subtitle="Consultas, recargas e lançamentos da sua própria conta, somente para leitura."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('client.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary" href="{{ route('client.credits.index') }}">Comprar créditos</a>
        </x-slot:actions>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Saldo atual</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($wallet->balance_cents) }}</p>
                <p class="stat-card__hint">Projeção do livro-razão</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Total consumido</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totalConsumedCents) }}</p>
                <p class="stat-card__hint">Débitos de consultas e ajustes</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Total creditado</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($totalCreditedCents) }}</p>
                <p class="stat-card__hint">Recargas confirmadas e ajustes</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Consultas</p>
                <p class="stat-card__value">{{ $consultations->total() }}</p>
                <p class="stat-card__hint">No filtro atual</p>
            </div>
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Consultas</h2>
            <form method="GET" action="{{ route('client.history') }}" class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label class="visually-hidden" for="status">Filtrar por status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Todos os status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="visually-hidden" for="modality">Filtrar por modalidade</label>
                    <select class="form-select form-select-sm" id="modality" name="modality">
                        <option value="">Todas as modalidades</option>
                        @foreach ($modalities as $modality)
                            <option value="{{ $modality->value }}" @selected(($filters['modality'] ?? '') === $modality->value)>
                                {{ $modality->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" variant="ghost" size="sm">Filtrar</x-ui.button>
                <a class="btn btn-ghost btn-sm" href="{{ route('client.history') }}">Limpar</a>
            </form>
        </x-ui.card-header>

        @if ($consultations->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhuma consulta encontrada"
                    text="Solicite uma leitura ou ajuste o filtro para ver seu histórico."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Histórico de consultas do cliente</caption>
                    <thead>
                        <tr>
                            <th scope="col">Solicitada em</th>
                            <th scope="col">Atendente</th>
                            <th scope="col">Modalidade</th>
                            <th scope="col">Status</th>
                            <th scope="col">Duração</th>
                            <th scope="col">Preço aplicado</th>
                            <th scope="col">Total cobrado</th>
                            <th scope="col" class="text-end">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($consultations as $consultation)
                            <tr>
                                <td class="text-nowrap">{{ $consultation->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>{{ $consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name }}</td>
                                <td>
                                    <x-ui.badge variant="gold">{{ $consultation->modalityLabel() }}</x-ui.badge>
                                </td>
                                <td>
                                    <x-ui.status-badge :status="$consultation->status->value" :label="$consultation->status->label()" />
                                </td>
                                <td class="text-nowrap">{{ $consultation->durationLabel() ?? '—' }}</td>
                                <td class="text-nowrap">{{ $consultation->pricePerMinuteLabel() ?? '—' }}<span class="form-text d-block">por minuto</span></td>
                                <td class="text-nowrap">
                                    {{ \App\Support\Money::format((int) ($consumedByConsultation[$consultation->id] ?? 0)) }}
                                    @if ($consultation->billed_minutes > 0)
                                        <span class="form-text d-block">{{ $consultation->billed_minutes }} minuto(s) cobrado(s)</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('consultations.show', $consultation) }}">Abrir</a>
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

    <div class="row g-4">
        <div class="col-lg-6">
            <x-ui.card class="h-100">
                <x-ui.card-header class="d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Recargas</h2>
                    <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.index') }}">Carteira</a>
                </x-ui.card-header>

                @if ($payments->isEmpty())
                    <x-ui.card-body>
                        <x-ui.empty-state
                            icon="◈"
                            title="Nenhuma recarga"
                            text="As recargas aparecerão aqui após a criação do pedido."
                        />
                    </x-ui.card-body>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Histórico de recargas do cliente</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Valor</th>
                                    <th scope="col">Créditos</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td class="text-nowrap">{{ ($payment->paid_at ?? $payment->created_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                                        <td class="text-nowrap">{{ \App\Support\Money::format($payment->amount_cents) }}</td>
                                        <td>{{ $payment->credits }}</td>
                                        <td><x-ui.status-badge :status="$payment->status->value" :label="$payment->status->label()" /></td>
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
        </div>

        <div class="col-lg-6">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Lançamentos da carteira</h2>
                </x-ui.card-header>

                @if ($ledgerEntries->isEmpty())
                    <x-ui.card-body>
                        <x-ui.empty-state
                            icon="◇"
                            title="Nenhum lançamento"
                            text="Créditos e débitos da sua carteira aparecerão aqui."
                        />
                    </x-ui.card-body>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Lançamentos da carteira do cliente</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Tipo</th>
                                    <th scope="col">Valor</th>
                                    <th scope="col">Saldo após</th>
                                    <th scope="col">Origem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ledgerEntries as $entry)
                                    <tr>
                                        <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                        <td>
                                            <span class="badge {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? 'text-bg-success' : 'text-bg-danger' }}">
                                                {{ $entry->type->label() }}
                                            </span>
                                        </td>
                                        <td class="text-nowrap">
                                            {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? '+' : '−' }}
                                            {{ \App\Support\Money::format($entry->amount_cents) }}
                                        </td>
                                        <td class="text-nowrap">{{ \App\Support\Money::format($entry->balance_after_cents) }}</td>
                                        <td>{{ $entry->sourceLabel() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-ui.card-footer>
                        {{ $ledgerEntries->links() }}
                    </x-ui.card-footer>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>

