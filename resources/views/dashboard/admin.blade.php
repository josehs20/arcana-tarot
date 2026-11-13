<x-layouts.panel variant="admin" title="Painel Administrativo">
    <x-layouts.page-header
        title="Painel do Administrador"
        subtitle="Visão geral da operação, usuários e consultas da plataforma."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('admin.consultations.index') }}">Consultas</a>
            <a class="btn btn-primary" href="{{ route('admin.users.index') }}">Gerenciar usuários</a>
        </x-slot:actions>
    </x-layouts.page-header>

    <section class="dashboard-section" aria-labelledby="dashboard-overview">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Visão geral</p>
                <h2 class="dashboard-section__title" id="dashboard-overview">Resumo da operação</h2>
            </div>
            <p class="dashboard-section__meta">
                <x-ui.icon name="clock" width="14" height="14" />
                Atualizado em {{ now()->format('d/m/Y H:i') }}
            </p>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Clientes"
                    :value="number_format($metrics->totalClients, 0, ',', '.')"
                    :hint="$metrics->activeClients.' com conta ativa'"
                    icon="users"
                    :href="route('admin.users.index', ['role' => 'client'])"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Atendentes"
                    :value="number_format($metrics->totalAttendants, 0, ',', '.')"
                    :hint="$metrics->onlineAttendants.' online · '.$metrics->busyAttendants.' em consulta'"
                    icon="user"
                    tone="gold"
                    :href="route('admin.users.index', ['role' => 'attendant'])"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Consultas ativas"
                    :value="number_format($metrics->activeConsultations, 0, ',', '.')"
                    :hint="$metrics->pendingConsultationRequests.' aguardando aceite'"
                    icon="activity"
                    tone="info"
                    :href="route('admin.consultations.index', ['status' => 'active'])"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Candidaturas pendentes"
                    :value="number_format($metrics->pendingApplications, 0, ',', '.')"
                    hint="Aguardando análise da equipe"
                    icon="inbox"
                    :tone="$metrics->pendingApplications > 0 ? 'warning' : 'default'"
                    :href="route('admin.applications.index')"
                />
            </div>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="dashboard-finance">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Financeiro</p>
                <h2 class="dashboard-section__title" id="dashboard-finance">Receita e comissões</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Abrir financeiro</a>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Recargas confirmadas"
                    :value="number_format($metrics->confirmedRecharges, 0, ',', '.')"
                    :hint="\App\Support\Money::format($metrics->confirmedRechargeCents).' recebidos'"
                    icon="credit-card"
                    tone="success"
                    :href="route('admin.payments.index', ['status' => 'confirmed'])"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Pagamentos pendentes"
                    :value="number_format($metrics->pendingPayments, 0, ',', '.')"
                    :hint="\App\Support\Money::format($metrics->pendingPaymentCents).' aguardando confirmação'"
                    icon="clock"
                    :tone="$metrics->pendingPayments > 0 ? 'warning' : 'default'"
                    :href="route('admin.payments.index', ['status' => 'pending'])"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Receita bruta de consultas"
                    :value="\App\Support\Money::format($metrics->grossConsultationRevenueCents)"
                    :hint="'Receita líquida de '.\App\Support\Money::format($metrics->netOperationalRevenueCents)"
                    icon="wallet"
                />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card
                    label="Comissões líquidas"
                    :value="\App\Support\Money::format($metrics->commissionNetCents)"
                    :hint="\App\Support\Money::format($metrics->commissionReversedCents).' estornados'"
                    icon="percent"
                    tone="gold"
                    :href="route('admin.commissions.index')"
                />
            </div>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="dashboard-modalities">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Modalidades</p>
                <h2 class="dashboard-section__title" id="dashboard-modalities">Texto, áudio e vídeo</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.modalities.index') }}">Configurar modalidades</a>
        </div>

        @include('dashboard.partials.modality-usage', [
            'rows' => $modalityUsage,
            'showCommission' => true,
            'revenueLabel' => 'Receita bruta',
            'indexRoute' => 'admin.consultations.index',
        ])
    </section>

    <section class="dashboard-section" aria-labelledby="dashboard-trends">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Últimos 14 dias</p>
                <h2 class="dashboard-section__title" id="dashboard-trends">Movimento da plataforma</h2>
            </div>
            <p class="dashboard-section__meta">{{ $consultationTrend['period'] }}</p>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="dashboard-card-head">
                        <div>
                            <h3 class="dashboard-card-head__title">Consultas por dia</h3>
                            <p class="dashboard-card-head__meta">Solicitações registradas na plataforma</p>
                        </div>
                        <span class="dashboard-card-head__total">{{ number_format($consultationTrend['total'], 0, ',', '.') }}</span>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.chart
                            :labels="$consultationTrend['labels']"
                            :values="$consultationTrend['values']"
                            :period="$consultationTrend['period']"
                            series-label="Consultas por dia"
                            type="line"
                        />
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-xl-5">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="dashboard-card-head">
                        <div>
                            <h3 class="dashboard-card-head__title">Recargas confirmadas</h3>
                            <p class="dashboard-card-head__meta">Valores efetivamente recebidos</p>
                        </div>
                        <span class="dashboard-card-head__total dashboard-card-head__total--currency">
                            {{ \App\Support\Money::format($rechargeTrend['total']) }}
                        </span>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.chart
                            :labels="$rechargeTrend['labels']"
                            :values="$rechargeTrend['values']"
                            :period="$rechargeTrend['period']"
                            series-label="Recargas confirmadas"
                            unit="currency"
                            type="bar"
                        />
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </section>


    <section class="dashboard-section" aria-labelledby="dashboard-activity">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Acompanhamento</p>
                <h2 class="dashboard-section__title" id="dashboard-activity">Atividade recente</h2>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.index') }}">Ver todas</a>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h3 class="dashboard-card-head__title">Consultas recentes</h3>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($recentConsultations->isEmpty())
                            <x-ui.empty-state
                                icon="✦"
                                title="Nenhuma consulta registrada"
                                text="As solicitações de leitura aparecerão aqui assim que forem criadas."
                            />
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0 dashboard-table">
                                    <caption class="visually-hidden">Consultas recentes da plataforma</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Cliente</th>
                                            <th scope="col">Atendente</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Solicitada em</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($recentConsultations as $consultation)
                                            <tr>
                                                <td>{{ $consultation->client?->name ?? '—' }}</td>
                                                <td>
                                                    {{ $consultation->attendant?->attendantProfile?->display_name ?? $consultation->attendant?->name ?? '—' }}
                                                </td>
                                                <td>
                                                    <x-ui.status-badge
                                                        :status="$consultation->status->value"
                                                        :label="$consultation->status->label()"
                                                    />
                                                </td>
                                                <td class="text-nowrap">
                                                    {{ ($consultation->started_at ?? $consultation->created_at)?->format('d/m/Y H:i') ?? '—' }}
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
                        <h3 class="dashboard-card-head__title mb-0">Candidaturas em análise</h3>
                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.applications.index') }}">Analisar</a>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($recentApplications->isEmpty())
                            <x-ui.empty-state
                                icon="✧"
                                title="Nenhuma candidatura"
                                text="Não há candidaturas aguardando análise neste momento."
                            />
                        @else
                            <ul class="panel-list list-unstyled mb-0">
                                @foreach ($recentApplications as $application)
                                    <li class="panel-list__item">
                                        <div>
                                            <p class="panel-list__title">{{ $application->display_name }}</p>
                                            <p class="panel-list__meta">
                                                Enviada em {{ $application->created_at?->format('d/m/Y') ?? '—' }}
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <x-ui.status-badge
                                                :status="$application->status->value"
                                            />
                                            <a class="btn btn-ghost btn-sm" href="{{ route('admin.applications.show', $application) }}">
                                                Revisar
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

    <section class="dashboard-section" aria-labelledby="dashboard-shortcuts">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Atalhos</p>
                <h2 class="dashboard-section__title" id="dashboard-shortcuts">Áreas de gestão</h2>
            </div>
        </div>

        <div class="dashboard-shortcuts">
            <a class="dashboard-shortcut" href="{{ route('admin.users.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="users" /></span>
                <span class="dashboard-shortcut__title">Usuários</span>
                <span class="dashboard-shortcut__text">Contas, papéis e ativação.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.applications.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="inbox" /></span>
                <span class="dashboard-shortcut__title">Candidaturas</span>
                <span class="dashboard-shortcut__text">Análise de novos atendentes.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.decks.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="layers" /></span>
                <span class="dashboard-shortcut__title">Baralhos</span>
                <span class="dashboard-shortcut__text">Catálogo de cartas e imagens.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.finance.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="wallet" /></span>
                <span class="dashboard-shortcut__title">Financeiro</span>
                <span class="dashboard-shortcut__text">Carteiras, preços e ajustes.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.payments.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="credit-card" /></span>
                <span class="dashboard-shortcut__title">Pagamentos</span>
                <span class="dashboard-shortcut__text">Recargas Pix e confirmações.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.commissions.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="percent" /></span>
                <span class="dashboard-shortcut__title">Comissões</span>
                <span class="dashboard-shortcut__text">Repasses e estornos.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.site.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="globe" /></span>
                <span class="dashboard-shortcut__title">Conteúdo do site</span>
                <span class="dashboard-shortcut__text">Seções, navegação e FAQ.</span>
            </a>
            <a class="dashboard-shortcut" href="{{ route('admin.audit.index') }}">
                <span class="dashboard-shortcut__icon" aria-hidden="true"><x-ui.icon name="shield" /></span>
                <span class="dashboard-shortcut__title">Auditoria</span>
                <span class="dashboard-shortcut__text">Histórico de ações sensíveis.</span>
            </a>
        </div>
    </section>
</x-layouts.panel>

