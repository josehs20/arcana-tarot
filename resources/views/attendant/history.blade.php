<x-layouts.panel variant="panel" title="Meu histórico">
    <x-layouts.page-header
        title="Meu histórico"
        subtitle="Seus atendimentos, taxas congeladas e comissões calculadas pelo servidor."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('attendant.dashboard') }}">Voltar ao painel</a>
        </x-slot:actions>
    </x-layouts.page-header>

    @if ($profile === null)
        <x-ui.alert type="warning" :dismissible="false">
            Seu perfil profissional ainda não foi criado. A administração precisa habilitá-lo antes de você receber consultas.
        </x-ui.alert>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Comissões geradas</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($summary->accruedCents) }}</p>
                <p class="stat-card__hint">Lançamentos originais</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Estornos</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($summary->reversedCents) }}</p>
                <p class="stat-card__hint">Revertidos por administração</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Comissão líquida</p>
                <p class="stat-card__value">{{ \App\Support\Money::format($summary->totalCents) }}</p>
                <p class="stat-card__hint">{{ \App\Support\Money::format($summary->monthCents) }} neste mês</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Taxa atual</p>
                <p class="stat-card__value">{{ $profile ? \App\Support\Rate::formatBps($profile->commission_rate_bps) : '—' }}</p>
                <p class="stat-card__hint">Vale apenas para novas consultas</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        A comissão é um lançamento contábil interno do MVP. Ela não gera transferência, saque ou saldo disponível automaticamente.
        Valores históricos não podem ser editados manualmente: correções são registradas como estorno auditado.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Consultas</h2>
            <form method="GET" action="{{ route('attendant.history') }}" class="d-flex flex-wrap align-items-end gap-2">
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
                <a class="btn btn-ghost btn-sm" href="{{ route('attendant.history') }}">Limpar</a>
            </form>
        </x-ui.card-header>

        @if ($consultations->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhuma consulta encontrada"
                    text="Seus atendimentos aparecerão aqui após a primeira solicitação."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Histórico de consultas do atendente</caption>
                    <thead>
                        <tr>
                            <th scope="col">Solicitada em</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Modalidade</th>
                            <th scope="col">Status</th>
                            <th scope="col">Duração</th>
                            <th scope="col">Preço aplicado</th>
                            <th scope="col">Total cobrado</th>
                            <th scope="col">Taxa congelada</th>
                            <th scope="col">Base</th>
                            <th scope="col">Comissão líquida</th>
                            <th scope="col" class="text-end">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($consultations as $consultation)
                            @php
                                $baseCents = (int) $consultation->commissionEntries
                                    ->where('type', \App\Enums\CommissionEntryType::Accrual)
                                    ->sum('base_amount_cents');
                                $commissionCents = (int) $consultation->commissionEntries
                                    ->sum(fn ($entry) => $entry->signedAmountCents());
                            @endphp
                            <tr>
                                <td class="text-nowrap">{{ $consultation->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>{{ $consultation->client->name }}</td>
                                <td><x-ui.badge variant="gold">{{ $consultation->modalityLabel() }}</x-ui.badge></td>
                                <td><x-ui.status-badge :status="$consultation->status->value" :label="$consultation->status->label()" /></td>
                                <td class="text-nowrap">{{ $consultation->durationLabel() ?? '—' }}</td>
                                <td class="text-nowrap">{{ $consultation->pricePerMinuteLabel() ?? '—' }}<span class="form-text d-block">por minuto</span></td>
                                <td class="text-nowrap">{{ $consultation->totalChargedLabel() }}</td>
                                <td>{{ $consultation->commission_rate_bps !== null ? \App\Support\Rate::formatBps($consultation->commission_rate_bps) : '—' }}</td>
                                <td class="text-nowrap">{{ $consultation->commissionEntries->isEmpty() ? '—' : \App\Support\Money::format($baseCents) }}</td>
                                <td class="text-nowrap">{{ $consultation->commissionEntries->isEmpty() ? '—' : \App\Support\Money::format($commissionCents) }}</td>
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


    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Lançamentos de comissão</h2>
            <form method="GET" action="{{ route('attendant.history') }}" class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label class="visually-hidden" for="type">Filtrar por tipo</label>
                    <select class="form-select form-select-sm" id="type" name="type">
                        <option value="">Todos os tipos</option>
                        @foreach ($commissionTypes as $type)
                            <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" variant="ghost" size="sm">Filtrar</x-ui.button>
            </form>
        </x-ui.card-header>

        @if ($commissionEntries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum lançamento de comissão"
                    text="A comissão é criada após o encerramento de uma consulta iniciada e efetivamente cobrada."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Histórico de comissões do atendente</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Consulta</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Taxa</th>
                            <th scope="col">Base</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Motivo / origem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($commissionEntries as $entry)
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
                {{ $commissionEntries->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

