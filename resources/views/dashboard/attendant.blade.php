<x-layouts.panel variant="panel" title="Painel do Atendente">
    <x-layouts.page-header
        title="Painel do Atendente"
        :subtitle="'Olá, ' . auth()->user()->name . '. Gerencie sua disponibilidade e acompanhe seus atendimentos.'"
    >
        <x-slot:actions>
            <x-ui.status-badge :status="$presence" />
            @if ($profile && ! $activeConsultation)
                <form
                    method="POST"
                    action="{{ route('attendant.availability.update') }}"
                    class="d-inline-flex flex-wrap gap-2"
                    aria-label="Alterar disponibilidade"
                >
                    @csrf
                    @method('PATCH')
                    @foreach ([\App\Enums\AttendantAvailability::Online, \App\Enums\AttendantAvailability::Busy, \App\Enums\AttendantAvailability::Offline] as $option)
                        <x-ui.button
                            type="submit"
                            size="sm"
                            name="availability"
                            value="{{ $option->value }}"
                            :variant="$profile->availability === $option ? 'primary' : 'ghost'"
                            :disabled="$profile->availability === $option"
                        >
                            {{ $option->label() }}
                        </x-ui.button>
                    @endforeach
                </form>
            @endif
        </x-slot:actions>
    </x-layouts.page-header>

    @error('availability')
        <x-ui.alert type="danger" :dismissible="false">{{ $message }}</x-ui.alert>
    @enderror

    @if (! $profile)
        <x-ui.alert type="warning" :dismissible="false">
            Seu perfil profissional ainda não foi criado. A administração precisa habilitar seu perfil para que você possa ficar online.
        </x-ui.alert>
    @endif

    <section class="dashboard-section" aria-labelledby="attendant-overview">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Visão geral</p>
                <h2 class="dashboard-section__title" id="attendant-overview">Seu momento</h2>
            </div>
            <p class="dashboard-section__meta">Atualizado em {{ now()->format('d/m/Y H:i') }}</p>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Status"
                    :hint="$activeConsultation ? 'Disponibilidade congelada durante a consulta' : 'Online recebe solicitações; ocupado pausa a fila'"
                    icon="activity"
                    :tone="$presence === 'online' ? 'success' : ($presence === 'busy' ? 'warning' : 'default')"
                >
                    <span class="stat-card__value stat-card__value--badge">
                        <x-ui.status-badge :status="$presence" />
                    </span>
                </x-ui.stat-card>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Pedidos pendentes"
                    hint="Aguardando seu aceite"
                    icon="inbox"
                    :tone="$pendingRequests->isNotEmpty() ? 'warning' : 'default'"
                >
                    <span class="stat-card__value" data-pending-count>{{ $pendingRequests->count() }}</span>
                </x-ui.stat-card>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Consultas concluídas"
                    :value="number_format($completedConsultationsCount, 0, ',', '.')"
                    hint="Encerradas até hoje"
                    icon="check-circle"
                    tone="success"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Comissão acumulada"
                    :value="\App\Support\Money::format($commissionSummary->totalCents)"
                    :hint="\App\Support\Money::format($commissionSummary->monthCents).' neste mês'"
                    icon="percent"
                    tone="gold"
                />
            </div>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="attendant-modalities">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Modalidades</p>
                <h2 class="dashboard-section__title" id="attendant-modalities">Seus atendimentos por modalidade</h2>
            </div>
            <p class="dashboard-section__meta">Somente os seus atendimentos</p>
        </div>

        @include('dashboard.partials.modality-usage', [
            'rows' => $modalityUsage,
            'showCommission' => true,
            'revenueLabel' => 'Receita gerada',
        ])
    </section>

    <section class="dashboard-section" aria-labelledby="attendant-trends">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Últimos 14 dias</p>
                <h2 class="dashboard-section__title" id="attendant-trends">Seu desempenho</h2>
            </div>
            <p class="dashboard-section__meta">{{ $consultationTrend['period'] }}</p>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="dashboard-card-head">
                        <div>
                            <h3 class="dashboard-card-head__title">Consultas recebidas</h3>
                            <p class="dashboard-card-head__meta">Solicitações criadas por clientes</p>
                        </div>
                        <span class="dashboard-card-head__total">{{ number_format($consultationTrend['total'], 0, ',', '.') }}</span>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.chart
                            :labels="$consultationTrend['labels']"
                            :values="$consultationTrend['values']"
                            :period="$consultationTrend['period']"
                            series-label="Consultas recebidas por dia"
                            type="line"
                        />
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-xl-5">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="dashboard-card-head">
                        <div>
                            <h3 class="dashboard-card-head__title">Comissões por dia</h3>
                            <p class="dashboard-card-head__meta">Valores líquidos de estornos</p>
                        </div>
                        <span class="dashboard-card-head__total dashboard-card-head__total--currency">
                            {{ \App\Support\Money::format($commissionTrend['total']) }}
                        </span>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.chart
                            :labels="$commissionTrend['labels']"
                            :values="$commissionTrend['values']"
                            :period="$commissionTrend['period']"
                            series-label="Comissões por dia"
                            unit="currency"
                            type="bar"
                        />
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>


    {{--
        Os ganchos do canal privado (`data-user-channel`) e o modal de
        aceite/recusa vêm do layout do painel, em `layouts.partials.realtime-alerts`:
        assim o aviso acompanha o atendente em qualquer tela. Aqui ficam apenas
        os elementos exclusivos desta página (fila, contadores e aviso local).
    --}}
    <section
        class="dashboard-section"
        aria-labelledby="attendant-queue"
    >
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Fila de atendimento</p>
                <h2 class="dashboard-section__title" id="attendant-queue">Pedidos pendentes</h2>
            </div>
            <span class="dashboard-section__badge @if ($pendingRequests->isEmpty()) d-none @endif" data-requests-badge>
                <span data-pending-count>{{ $pendingRequests->count() }}</span> aguardando
            </span>
        </div>

        <div class="alert alert-info py-2 px-3 d-none" data-user-notice role="status" aria-live="polite"></div>

        <x-ui.card>
            <x-ui.card-body>
                <div data-requests-empty @class(['d-none' => $pendingRequests->isNotEmpty()])>
                    <x-ui.empty-state
                        icon="✧"
                        title="Nenhum pedido pendente"
                        text="Quando um cliente solicitar sua leitura, o pedido aparece aqui para aceite ou recusa."
                    />
                </div>

                <ul
                    class="panel-list list-unstyled mb-0 @if ($pendingRequests->isEmpty()) d-none @endif"
                    data-requests-list
                >
                    @foreach ($pendingRequests as $requestItem)
                        <li class="panel-list__item" data-request-id="{{ $requestItem->id }}">
                            <div>
                                <p class="panel-list__title">{{ $requestItem->client?->name ?? 'Cliente' }}</p>
                                <p class="panel-list__meta">
                                    {{ $requestItem->modalityLabel() }} · {{ $requestItem->pricePerMinuteLabel() ?? '—' }}/min ·
                                    solicitada em {{ $requestItem->created_at?->format('d/m/Y H:i') ?? '—' }}
                                </p>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <a class="btn btn-ghost btn-sm" href="{{ route('consultations.show', $requestItem) }}">
                                    Ver detalhes
                                </a>
                                @can('accept', $requestItem)
                                    <form method="POST" action="{{ route('attendant.consultations.accept', $requestItem) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="primary" size="sm">Aceitar</x-ui.button>
                                    </form>
                                @endcan
                                @can('reject', $requestItem)
                                    <form method="POST" action="{{ route('attendant.consultations.reject', $requestItem) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="danger" size="sm">Recusar</x-ui.button>
                                    </form>
                                @endcan
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="form-text mt-3 mb-0">
                    O aceite inicia a consulta e congela sua disponibilidade como <strong>Ocupado</strong>.
                    A recusa encerra a solicitação sem cobrança.
                </p>
            </x-ui.card-body>
        </x-ui.card>
    </section>


    <section class="dashboard-section" aria-labelledby="attendant-current">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Em andamento</p>
                <h2 class="dashboard-section__title" id="attendant-current">Consulta ativa</h2>
            </div>
        </div>

        <x-ui.card>
            <x-ui.card-body>
                @if ($activeConsultation)
                    <div class="consultation-highlight">
                        <div>
                            <p class="consultation-highlight__label">Cliente em atendimento</p>
                            <p class="consultation-highlight__name">{{ $activeConsultation->client?->name ?? 'Cliente' }}</p>
                            <dl class="consultation-highlight__meta mb-0">
                                <div>
                                    <dt>Início</dt>
                                    <dd>{{ $activeConsultation->started_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Modalidade</dt>
                                    <dd>{{ $activeConsultation->modalityLabel() }} · {{ $activeConsultation->pricePerMinuteLabel() ?? '—' }}/min</dd>
                                </div>
                                <div>
                                    <dt>Mensagens novas</dt>
                                    <dd>{{ $unreadMessagesCount }} nova(s)</dd>
                                </div>
                                <div>
                                    <dt>Status</dt>
                                    <dd><x-ui.status-badge :status="$activeConsultation->status->value" :label="$activeConsultation->status->label()" /></dd>
                                </div>
                            </dl>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <a class="btn btn-primary btn-sm" href="{{ route('consultations.show', $activeConsultation) }}">
                                Abrir atendimento
                            </a>
                            @can('end', $activeConsultation)
                                <form method="POST" action="{{ route('consultations.end', $activeConsultation) }}">
                                    @csrf
                                    @method('PATCH')
                                    <x-ui.button type="submit" variant="danger" size="sm">Encerrar consulta</x-ui.button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @else
                    <x-ui.empty-state
                        icon="☾"
                        title="Nenhuma consulta ativa"
                        text="Quando você aceitar um pedido, o acompanhamento da leitura aparece aqui."
                    />
                @endif
            </x-ui.card-body>
        </x-ui.card>
    </section>


    <section class="dashboard-section" aria-labelledby="attendant-history">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Histórico</p>
                <h2 class="dashboard-section__title" id="attendant-history">Atendimentos recentes</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('attendant.history') }}">Ver histórico completo</a>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h3 class="dashboard-card-head__title">Últimas consultas encerradas</h3>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($history->isEmpty())
                            <x-ui.empty-state
                                icon="✦"
                                title="Nenhum atendimento concluído"
                                text="As consultas finalizadas aparecem aqui com status e comissão."
                            />
                        @else
                            <ul class="panel-list list-unstyled mb-0">
                                @foreach ($history as $consultation)
                                    <li class="panel-list__item">
                                        <div>
                                            <p class="panel-list__title">{{ $consultation->client?->name ?? 'Cliente' }}</p>
                                            <p class="panel-list__meta">
                                                {{ $consultation->modalityLabel() }} · {{ $consultation->durationLabel() ?? '—' }} ·
                                                {{ $consultation->ended_at?->format('d/m/Y H:i') ?? $consultation->updated_at?->format('d/m/Y H:i') ?? '—' }}
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <x-ui.status-badge
                                                :status="$consultation->status->value"
                                                :label="$consultation->status->label()"
                                            />
                                            <span class="panel-list__amount">
                                                {{ \App\Support\Money::format((int) $consultation->commissionEntries->sum(fn ($entry): int => $entry->signedAmountCents())) }}
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>


            <div class="col-xl-5">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h3 class="dashboard-card-head__title">Comissões recentes</h3>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($commissionEntries->isEmpty())
                            <x-ui.empty-state
                                icon="✧"
                                title="Sem lançamentos"
                                text="Cada consulta concluída gera um lançamento de comissão."
                            />
                        @else
                            <ul class="panel-list list-unstyled mb-0">
                                @foreach ($commissionEntries as $entry)
                                    <li class="panel-list__item">
                                        <div>
                                            <p class="panel-list__title">{{ $entry->type->label() }}</p>
                                            <p class="panel-list__meta">
                                                @if ($entry->consultation)
                                                    <a href="{{ route('consultations.show', $entry->consultation) }}">
                                                        Consulta #{{ $entry->consultation_id }}
                                                    </a>
                                                @else
                                                    Consulta #{{ $entry->consultation_id }}
                                                @endif
                                                · {{ $entry->created_at?->format('d/m/Y') ?? '—' }}
                                            </p>
                                        </div>
                                        <span class="panel-list__amount {{ $entry->signedAmountCents() < 0 ? 'panel-list__amount--negative' : '' }}">
                                            {{ \App\Support\Money::format($entry->signedAmountCents()) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>


    <section class="dashboard-section" aria-labelledby="attendant-profile">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Conta profissional</p>
                <h2 class="dashboard-section__title" id="attendant-profile">Meu perfil</h2>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <x-ui.card class="h-100">
                    <x-ui.card-body>
                        @if ($profile)
                            <dl class="panel-details">
                                <div class="panel-details__row">
                                    <dt>Nome público</dt>
                                    <dd>{{ $profile->display_name }}</dd>
                                </div>
                                <div class="panel-details__row">
                                    <dt>Disponibilidade</dt>
                                    <dd><x-ui.status-badge :status="$presence" /></dd>
                                </div>
                                <div class="panel-details__row">
                                    <dt>Comissão atual</dt>
                                    <dd>
                                        {{ $commissionRateLabel ?? '—' }}
                                        <span class="d-block form-text">Definida pela administração</span>
                                    </dd>
                                </div>
                                <div class="panel-details__row">
                                    <dt>Perfil público</dt>
                                    <dd>
                                        @if ($profile->is_published)
                                            <x-ui.status-badge status="online" label="Publicado" />
                                        @else
                                            <x-ui.status-badge status="offline" label="Não publicado" />
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        @else
                            <x-ui.empty-state
                                icon="✦"
                                title="Perfil não encontrado"
                                text="Fale com a administração para criar seu perfil profissional."
                            />
                        @endif
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-lg-4">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <p class="dashboard-card-head__title mb-0">Atalhos</p>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <ul class="list-unstyled d-grid gap-2 mb-0">
                            @if ($profile && $profile->is_published)
                                <li>
                                    <a class="btn btn-ghost w-100" href="{{ route('attendants.show', $profile) }}">
                                        Ver perfil público
                                    </a>
                                </li>
                            @endif
                            <li>
                                <a class="btn btn-ghost w-100" href="{{ route('attendant.history') }}">Meu histórico</a>
                            </li>
                            <li>
                                <a class="btn btn-ghost w-100" href="{{ route('home') }}#como-funciona">Como funciona</a>
                            </li>
                        </ul>
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>
</x-layouts.panel>

