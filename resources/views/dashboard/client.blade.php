<x-layouts.panel variant="panel" title="Painel do Cliente | Arcana Tarot">
    <x-layouts.page-header
        title="Painel do Cliente"
        :subtitle="'Olá, ' . auth()->user()->name . '. Acompanhe suas consultas, saldo e recargas em um só lugar.'"
    >
        <x-slot:actions>
            <x-ui.status-badge status="online" label="Online" />
            <a class="btn btn-primary btn-sm" href="{{ route('client.credits.index') }}">Recarregar créditos</a>
        </x-slot:actions>
    </x-layouts.page-header>

    {{-- O canal privado (`data-user-channel`) vem do layout do painel. --}}
    <section
        class="dashboard-section"
        aria-labelledby="client-overview"
    >
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Visão geral</p>
                <h2 class="dashboard-section__title" id="client-overview">Sua conta</h2>
            </div>
            <p class="dashboard-section__meta">Valor por minuto: {{ $pricePerMinuteLabel }}</p>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Saldo disponível"
                    :value="$wallet ? \App\Support\Money::format($wallet->balance_cents) : \App\Support\Money::format(0)"
                    :hint="$wallet ? 'Atualizado em '.$wallet->updated_at?->format('d/m/Y H:i') : 'Recarregue para iniciar uma leitura'"
                    :href="route('client.credits.index')"
                    icon="wallet"
                    tone="gold"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Consultas realizadas"
                    :value="number_format($consultationsCount, 0, ',', '.')"
                    hint="Solicitações criadas por você"
                    :href="route('client.history')"
                    icon="sparkles"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Atendentes disponíveis"
                    :value="number_format($availableAttendantsCount, 0, ',', '.')"
                    hint="Online e sem atendimento ativo"
                    :href="route('attendants.index')"
                    icon="users"
                    :tone="$availableAttendantsCount > 0 ? 'success' : 'default'"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Mensagens novas"
                    :value="number_format($unreadMessagesCount, 0, ',', '.')"
                    :hint="$currentConsultation ? 'Na sua consulta em aberto' : 'Nenhuma consulta em aberto'"
                    icon="message-circle"
                    :tone="$unreadMessagesCount > 0 ? 'warning' : 'default'"
                />
            </div>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="client-modalities">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Modalidades</p>
                <h2 class="dashboard-section__title" id="client-modalities">Como você foi atendido</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('client.history') }}">Ver histórico</a>
        </div>

        @include('dashboard.partials.modality-usage', [
            'rows' => $modalityUsage,
            'revenueLabel' => 'Total consumido',
        ])
    </section>

    <section class="dashboard-section" aria-labelledby="client-spending">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Últimos 14 dias</p>
                <h2 class="dashboard-section__title" id="client-spending">Gastos com consultas</h2>
            </div>
            <p class="dashboard-section__meta">{{ $spendingTrend['period'] }}</p>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="dashboard-card-head">
                        <div>
                            <h3 class="dashboard-card-head__title">Débitos por dia</h3>
                            <p class="dashboard-card-head__meta">Valores consumidos da carteira</p>
                        </div>
                        <span class="dashboard-card-head__total dashboard-card-head__total--currency">
                            {{ \App\Support\Money::format($spendingTrend['total']) }}
                        </span>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.chart
                            :labels="$spendingTrend['labels']"
                            :values="$spendingTrend['values']"
                            :period="$spendingTrend['period']"
                            series-label="Gastos com consultas por dia"
                            unit="currency"
                            type="bar"
                        />
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-xl-4">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="d-flex justify-content-between align-items-center">
                        <h3 class="dashboard-card-head__title mb-0">Carteira</h3>
                        <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.index') }}">Recarregar</a>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($walletEntries->isEmpty())
                            <x-ui.empty-state
                                icon="✦"
                                title="Sem movimentações"
                                text="Suas recargas e débitos de consulta aparecerão aqui."
                            />
                        @else
                            <ul class="panel-list list-unstyled mb-0">
                                @foreach ($walletEntries as $entry)
                                    <li class="panel-list__item">
                                        <div>
                                            <p class="panel-list__title">{{ $entry->type->label() }}</p>
                                            <p class="panel-list__meta">
                                                {{ $entry->referenceLabel() ?? 'Ajuste de carteira' }}
                                                · {{ $entry->created_at?->format('d/m/Y') ?? '—' }}
                                            </p>
                                        </div>
                                        <span class="panel-list__amount {{ $entry->type === \App\Enums\LedgerEntryType::Debit ? 'panel-list__amount--negative' : '' }}">
                                            {{ $entry->type === \App\Enums\LedgerEntryType::Debit ? '-' : '+' }}{{ \App\Support\Money::format($entry->amount_cents) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="form-text mt-3 mb-0">
                                Saldo atual: <strong>{{ \App\Support\Money::format($wallet->balance_cents ?? 0) }}</strong>
                            </p>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>


    <section class="dashboard-section" aria-labelledby="client-consultations">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Atendimento</p>
                <h2 class="dashboard-section__title" id="client-consultations">Minha consulta</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('client.history') }}">Ver histórico</a>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <x-ui.card class="h-100">
                    <x-ui.card-body>
                        @if ($currentConsultation)
                            <div class="consultation-highlight">
                                <div>
                                    <p class="consultation-highlight__label">Consulta em aberto</p>
                                    <p class="consultation-highlight__name">
                                        {{ $currentConsultation->attendant?->attendantProfile?->display_name ?? $currentConsultation->attendant?->name ?? 'Atendente' }}
                                    </p>
                                    <dl class="consultation-highlight__meta mb-0">
                                        <div>
                                            <dt>Status</dt>
                                            <dd><x-ui.status-badge :status="$currentConsultation->status->value" :label="$currentConsultation->status->label()" /></dd>
                                        </div>
                                        <div>
                                            <dt>Modalidade</dt>
                                            <dd>{{ $currentConsultation->modalityLabel() }} · {{ $currentConsultation->pricePerMinuteLabel() ?? '—' }}/min</dd>
                                        </div>
                                        <div>
                                            <dt>Início</dt>
                                            <dd>{{ $currentConsultation->started_at?->format('d/m/Y H:i') ?? 'Aguardando aceite' }}</dd>
                                        </div>
                                        <div>
                                            <dt>Mensagens novas</dt>
                                            <dd>{{ $unreadMessagesCount }} nova(s)</dd>
                                        </div>
                                    </dl>
                                </div>
                                <a class="btn btn-primary btn-sm" href="{{ route('consultations.show', $currentConsultation) }}">
                                    Abrir atendimento
                                </a>
                            </div>
                        @else
                            <x-ui.empty-state
                                icon="✦"
                                title="Nenhuma consulta por aqui"
                                text="Quando você agendar uma leitura com um atendente, ela aparecerá nesta lista com todo o histórico de mensagens."
                            >
                                <a class="btn btn-primary mt-2" href="{{ route('home') }}#atendentes">Conhecer atendentes</a>
                            </x-ui.empty-state>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-xl-4">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h3 class="dashboard-card-head__title">Atalhos</h3>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <ul class="list-unstyled d-grid gap-2 mb-0">
                            <li><a class="btn btn-ghost w-100" href="{{ route('client.credits.index') }}">Comprar créditos</a></li>
                            <li><a class="btn btn-ghost w-100" href="{{ route('attendants.index') }}">Buscar atendentes</a></li>
                            <li><a class="btn btn-ghost w-100" href="{{ route('home') }}#como-funciona">Como funciona</a></li>
                            <li><a class="btn btn-ghost w-100" href="{{ route('home') }}#faq">Dúvidas frequentes</a></li>
                        </ul>
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>


    <section class="dashboard-section" aria-labelledby="client-history">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Registros</p>
                <h2 class="dashboard-section__title" id="client-history">Histórico e recargas</h2>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h3 class="dashboard-card-head__title">Consultas anteriores</h3>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($history->isEmpty())
                            <x-ui.empty-state
                                icon="☾"
                                title="Nenhuma consulta concluída"
                                text="As leituras finalizadas ficam registradas aqui."
                            />
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0 dashboard-table">
                                    <caption class="visually-hidden">Suas consultas anteriores</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Atendente</th>
                                            <th scope="col">Modalidade</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Encerrada em</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($history as $consultation)
                                            <tr>
                                                <td>
                                                    {{ $consultation->attendant?->attendantProfile?->display_name ?? $consultation->attendant?->name ?? '—' }}
                                                </td>
                                                <td>{{ $consultation->modalityLabel() }}</td>
                                                <td>
                                                    <x-ui.status-badge
                                                        :status="$consultation->status->value"
                                                        :label="$consultation->status->label()"
                                                    />
                                                </td>
                                                <td class="text-nowrap">
                                                    {{ $consultation->ended_at?->format('d/m/Y H:i') ?? '—' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>


            <div class="col-xl-5">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="d-flex justify-content-between align-items-center">
                        <h3 class="dashboard-card-head__title mb-0">Recargas recentes</h3>
                        <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.index') }}">Nova recarga</a>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($payments->isEmpty())
                            <x-ui.empty-state
                                icon="✧"
                                title="Nenhuma recarga"
                                text="Compre créditos via Pix para começar suas consultas."
                            />
                        @else
                            <ul class="panel-list list-unstyled mb-0">
                                @foreach ($payments as $payment)
                                    <li class="panel-list__item">
                                        <div>
                                            <p class="panel-list__title">{{ \App\Support\Money::format($payment->amount_cents) }}</p>
                                            <p class="panel-list__meta">
                                                {{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <x-ui.status-badge
                                                :status="$payment->status->value"
                                                :label="$payment->status->label()"
                                            />
                                            <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.payments.show', $payment) }}">
                                                Detalhes
                                            </a>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="client-attendants">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Diretório</p>
                <h2 class="dashboard-section__title" id="client-attendants">Atendentes disponíveis</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('attendants.index') }}">Ver todos</a>
        </div>

        @if ($availableAttendants->isEmpty())
            <x-ui.card>
                <x-ui.card-body>
                    <x-ui.empty-state
                        icon="☾"
                        title="Nenhum atendente disponível"
                        text="Todos os atendentes estão ocupados ou offline neste momento. Tente novamente em instantes."
                    />
                </x-ui.card-body>
            </x-ui.card>
        @else
            <div class="row g-3 g-xl-4">
                @foreach ($availableAttendants as $attendantProfile)
                    <div class="col-sm-6 col-xl-4">
                        <x-ui.card class="h-100">
                            <x-ui.card-body>
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <p class="panel-list__title mb-1">{{ $attendantProfile->display_name }}</p>
                                        <p class="panel-list__meta mb-0">
                                            {{ $pricePerMinuteLabel ? $pricePerMinuteLabel.' / min' : 'Valor por minuto definido pela plataforma' }}
                                        </p>
                                    </div>
                                    <x-ui.status-badge status="online" label="Online" />
                                </div>
                                <a class="btn btn-ghost btn-sm w-100 mt-3" href="{{ route('attendants.show', $attendantProfile) }}">
                                    Ver perfil
                                </a>
                            </x-ui.card-body>
                        </x-ui.card>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.panel>

