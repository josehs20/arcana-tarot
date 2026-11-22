<x-layouts.panel variant="admin" title="Acessos">
    <x-layouts.page-header
        title="Acessos ao site"
        subtitle="Quem visitou, quando entrou, quanto tempo ficou e por onde navegou."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('admin.access.index') }}">Atualizar</a>
            <a class="btn btn-ghost" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot:actions>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        Privacidade primeiro: visitantes sem cadastro são identificados por um cookie aleatório e aparecem apenas como
        "Visitante anônimo". O IP é guardado somente como hash (nunca em texto claro) e não é exibido nesta tela.
        O painel administrativo não entra nas estatísticas e o histórico é apagado após
        {{ config('access_log.retention_days') }} dias.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.access.index') }}" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="period">Período</label>
                    <select class="form-select" id="period" name="period">
                        @foreach ([7 => 'Últimos 7 dias', 14 => 'Últimos 14 dias', 30 => 'Últimos 30 dias'] as $value => $label)
                            <option value="{{ $value }}" @selected((int) $filters['period'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="audience">Tipo de visitante</label>
                    <select class="form-select" id="audience" name="audience">
                        <option value="">Todos</option>
                        <option value="registered" @selected($filters['audience'] === 'registered')>Com conta</option>
                        <option value="anonymous" @selected($filters['audience'] === 'anonymous')>Sem cadastro</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] }}"
                        placeholder="Nome, e-mail, código do visitante ou página"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.access.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <section class="dashboard-section" aria-labelledby="access-overview">
        <div class="dashboard-section__head">
            <div>
                <p class="dashboard-section__eyebrow">Resumo do período</p>
                <h2 class="dashboard-section__title" id="access-overview">{{ $trend['period'] }}</h2>
            </div>
            <p class="dashboard-section__meta">
                <x-ui.icon name="clock" width="14" height="14" />
                Atualizado em {{ now()->format('d/m/Y H:i') }}
            </p>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-sm-6 col-xl">
                <x-ui.stat-card
                    label="Acessos"
                    :value="number_format($metrics['visits'], 0, ',', '.')"
                    :hint="$metrics['today'].' hoje · '.$metrics['online'].' online agora'"
                    icon="activity"
                />
            </div>
            <div class="col-sm-6 col-xl">
                <x-ui.stat-card
                    label="Visitantes únicos"
                    :value="number_format($metrics['unique'], 0, ',', '.')"
                    :hint="number_format($metrics['page_views'], 0, ',', '.').' páginas vistas'"
                    icon="users"
                    tone="info"
                />
            </div>
            <div class="col-sm-6 col-xl">
                <x-ui.stat-card
                    label="Sem cadastro"
                    :value="number_format($metrics['anonymous'], 0, ',', '.')"
                    :hint="number_format($metrics['registered'], 0, ',', '.').' visitas de contas cadastradas'"
                    icon="user"
                    tone="gold"
                />
            </div>
            <div class="col-sm-6 col-xl">
                <x-ui.stat-card
                    label="Tempo médio"
                    :value="$metrics['visits'] > 0 ? \App\Models\SiteVisit::formatDuration($metrics['average_seconds']) : '—'"
                    hint="Tempo ativo por visita"
                    icon="clock"
                />
            </div>
            <div class="col-sm-6 col-xl">
                <x-ui.stat-card
                    label="Online agora"
                    :value="number_format($metrics['online'], 0, ',', '.')"
                    :hint="'Atividade nos últimos '.config('access_log.online_window_minutes').' minutos'"
                    icon="eye"
                    :tone="$metrics['online'] > 0 ? 'success' : 'default'"
                />
            </div>
        </div>
    </section>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="dashboard-card-head">
            <div>
                <h3 class="dashboard-card-head__title">Acessos por dia</h3>
                <p class="dashboard-card-head__meta">Visitas iniciadas em cada dia do período</p>
            </div>
            <span class="dashboard-card-head__total">{{ number_format($trend['total'], 0, ',', '.') }}</span>
        </x-ui.card-header>
        <x-ui.card-body>
            <x-ui.chart
                :labels="$trend['labels']"
                :values="$trend['values']"
                :period="$trend['period']"
                series-label="Acessos por dia"
                type="bar"
            />
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="dashboard-card-head__title">Visitas no período</h3>
                <p class="dashboard-card-head__meta mb-0">Cada linha é uma sessão, do primeiro ao último sinal de atividade.</p>
            </div>
            <span class="badge badge-info">{{ number_format($visits->total(), 0, ',', '.') }} no total</span>
        </x-ui.card-header>

        @if ($visits->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhuma visita encontrada"
                    text="Ajuste o período, o tipo de visitante ou a busca para ver outros acessos."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Visitas ao site registradas no período</caption>
                    <thead>
                        <tr>
                            <th scope="col">Visitante</th>
                            <th scope="col">Início</th>
                            <th scope="col">Fim</th>
                            <th scope="col">Duração</th>
                            <th scope="col">Páginas</th>
                            <th scope="col">Entrada</th>
                            <th scope="col">Última página</th>
                            <th scope="col">Navegador</th>
                            <th scope="col">Origem</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($visits as $visit)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-semibold">{{ $visit->visitorLabel() }}</span>
                                        <x-ui.badge :variant="$visit->audienceBadgeVariant()">{{ $visit->audienceLabel() }}</x-ui.badge>
                                    </div>
                                    <div class="form-text">
                                        @if ($visit->user)
                                            {{ $visit->user->email }}
                                        @else
                                            Código {{ $visit->visitorCode() }}
                                        @endif
                                    </div>
                                </td>
                                <td class="text-nowrap">{{ $visit->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-nowrap">
                                    @if ($visit->isActive())
                                        <x-ui.badge variant="success">Em andamento</x-ui.badge>
                                    @else
                                        {{ ($visit->ended_at ?? $visit->last_activity_at)?->format('d/m/Y H:i') ?? '—' }}
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $visit->durationLabel() }}</td>
                                <td>{{ number_format($visit->page_views, 0, ',', '.') }}</td>
                                <td><code>{{ $visit->entry_path }}</code></td>
                                <td><code>{{ $visit->last_path }}</code></td>
                                <td>
                                    {{ $visit->browser ?? 'Desconhecido' }}
                                    <div class="form-text">
                                        {{ collect([$visit->platform, $visit->deviceLabel()])->filter()->implode(' · ') ?: 'Dispositivo não identificado' }}
                                    </div>
                                </td>
                                <td>{{ $visit->referrer_host ?? 'Acesso direto' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($visits->hasPages())
            <x-ui.card-footer>{{ $visits->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

