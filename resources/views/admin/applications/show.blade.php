@php
    $statusLabels = [
        'pending' => 'Pendente',
        'under_review' => 'Em análise',
        'approved' => 'Aprovado',
        'rejected' => 'Rejeitado',
    ];
@endphp

<x-layouts.panel variant="admin" title="Candidatura — {{ $application->name }} | Arcana Tarot">
    <x-layouts.page-header
        :title="$application->name"
        :subtitle="'Candidatura #' . $application->id . ' · enviada em ' . ($application->submitted_at?->format('d/m/Y H:i') ?? 'data não registrada')"
    >
        <x-slot name="actions">
            <x-ui.status-badge :status="$application->status->value" />
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.applications.index') }}">Voltar à lista</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Dados fornecidos</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="application-detail__label">Nome completo</p>
                            <p class="application-detail__value">{{ $application->name }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="application-detail__label">E-mail</p>
                            <p class="application-detail__value">
                                <a href="mailto:{{ $application->email }}">{{ $application->email }}</a>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="application-detail__label">Telefone / WhatsApp</p>
                            <p class="application-detail__value">
                                @if ($application->phone)
                                    <a href="tel:{{ preg_replace('/\D+/', '', $application->phone) }}">
                                        {{ $application->formattedPhone() }}
                                    </a>
                                @else
                                    —
                                @endif
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="application-detail__label">Local</p>
                            <p class="application-detail__value">{{ $application->location() ?? '—' }}</p>
                        </div>
                    </div>

                    <p class="application-detail__label">Experiência com Tarot</p>
                    <div class="application-detail__value">{{ $application->experience_summary }}</div>

                    <p class="application-detail__label">Apresentação e abordagem de atendimento</p>
                    <div class="application-detail__value">{{ $application->message }}</div>

                    <p class="application-detail__label">Áreas de especialidade</p>
                    <div class="application-detail__value">
                        @forelse (($application->specialties ?? []) as $specialty)
                            <x-ui.badge variant="secondary" class="me-1 mb-1">{{ $specialty }}</x-ui.badge>
                        @empty
                            Não informadas.
                        @endforelse
                    </div>

                    <p class="application-detail__label">Portfólio / rede social</p>
                    <div class="application-detail__value">
                        @if ($application->portfolio_url)
                            <a href="{{ $application->portfolio_url }}" target="_blank" rel="noopener noreferrer nofollow">
                                {{ $application->portfolio_url }}
                            </a>
                        @else
                            Não informado.
                        @endif
                    </div>

                    <p class="application-detail__label">Consentimento para análise</p>
                    <p class="application-detail__value mb-0">
                        {{ $application->consent_at?->format('d/m/Y H:i') ?? 'Não registrado' }}
                    </p>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Revisão interna</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <p class="application-detail__label">Revisor atual</p>
                    <p class="application-detail__value">
                        {{ $application->reviewer?->name ?? 'Ainda não assumida' }}
                    </p>

                    @if ($application->review_started_at)
                        <p class="application-detail__label">Análise iniciada em</p>
                        <p class="application-detail__value">{{ $application->review_started_at->format('d/m/Y H:i') }}</p>
                    @endif

                    @if ($application->reviewed_at)
                        <p class="application-detail__label">Decisão registrada em</p>
                        <p class="application-detail__value">{{ $application->reviewed_at->format('d/m/Y H:i') }}</p>
                    @endif

                    <p class="application-detail__label">Nota interna atual</p>
                    <div class="application-detail__value">
                        {{ $application->review_notes ?: 'Nenhuma nota registrada.' }}
                    </div>

                    @if ($application->canStartReview())
                        <hr>
                        <h3 class="h6">Assumir análise</h3>
                        <p class="form-text">
                            A candidatura passa para <strong>em análise</strong> e fica registrada
                            em seu nome. A decisão pode ser tomada em seguida.
                        </p>
                        <form method="POST" action="{{ route('admin.applications.start', $application) }}">
                            @csrf
                            @method('PATCH')

                            <label class="form-label" for="start_note">Nota interna (opcional)</label>
                            <textarea
                                class="form-control mb-3"
                                id="start_note"
                                name="internal_note"
                                rows="2"
                                maxlength="2000"
                                placeholder="Observações internas sobre a análise"
                            >{{ old('internal_note') }}</textarea>

                            <x-ui.button type="submit" variant="secondary" size="sm" block>
                                Assumir análise
                            </x-ui.button>
                        </form>
                    @endif

                    @if ($application->canBeDecided())
                        <hr>
                        <h3 class="h6">Decidir candidatura</h3>
                        <p class="form-text">
                            A decisão é definitiva para esta candidatura e registra autor e data.
                            A nota é interna e nunca aparece no site público.
                        </p>

                        <form
                            method="POST"
                            action="{{ route('admin.applications.approve', $application) }}"
                            data-confirm="Aprovar esta candidatura? A aprovação não cria conta de atendente automaticamente."
                            class="mb-3"
                        >
                            @csrf
                            @method('PATCH')

                            <label class="form-label" for="approve_note">Nota da aprovação (opcional)</label>
                            <textarea
                                class="form-control mb-2"
                                id="approve_note"
                                name="internal_note"
                                rows="2"
                                maxlength="2000"
                            ></textarea>

                            <button type="submit" class="btn btn-success w-100">
                                Aprovar candidatura
                            </button>
                        </form>

                        <form
                            method="POST"
                            action="{{ route('admin.applications.reject', $application) }}"
                            data-confirm="Rejeitar esta candidatura? A pessoa poderá tentar novamente conforme a política de reaplicação."
                        >
                            @csrf
                            @method('PATCH')

                            <label class="form-label" for="reject_note">Nota da rejeição (opcional)</label>
                            <textarea
                                class="form-control mb-2"
                                id="reject_note"
                                name="internal_note"
                                rows="2"
                                maxlength="2000"
                            ></textarea>

                            <button type="submit" class="btn btn-danger w-100">
                                Rejeitar candidatura
                            </button>
                        </form>
                    @else
                        <hr>
                        <p class="mb-0">
                            Esta candidatura já foi decidida como
                            <strong>{{ $statusLabels[$application->status->value] ?? $application->status->value }}</strong>.
                            O histórico completo está abaixo.
                        </p>
                    @endif
                </x-ui.card-body>
            </x-ui.card>

            <x-ui.alert type="info" :dismissible="false" class="mt-3">
                Aprovar a candidatura <strong>não cria usuário, senha ou papel de atendente</strong>.
                A criação de acesso deve seguir o fluxo administrativo seguro e manual definido pela equipe.
            </x-ui.alert>

        </div>
    </div>

    @php
        $actionLabels = [
            'attendant_application.under_review' => 'Análise assumida',
            'attendant_application.approved' => 'Candidatura aprovada',
            'attendant_application.rejected' => 'Candidatura rejeitada',
        ];
    @endphp

    <x-ui.card class="mt-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Histórico de revisão</h2>
        </x-ui.card-header>
        <x-ui.card-body>
            @if ($application->auditLogs->isEmpty())
                <p class="mb-0">Nenhuma ação administrativa registrada até agora.</p>
            @else
                <ul class="application-timeline">
                    @foreach ($application->auditLogs as $log)
                        <li class="application-timeline__item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <strong>{{ $actionLabels[$log->action] ?? $log->action }}</strong>
                                @if ($log->created_at)
                                    <time class="form-text" datetime="{{ $log->created_at->toIso8601String() }}">
                                        {{ $log->created_at->format('d/m/Y H:i') }}
                                    </time>
                                @endif
                            </div>
                            <p class="form-text mb-1">
                                Por {{ $log->actor?->name ?? 'sistema' }}
                                @php
                                    $from = $log->metadata['from'] ?? null;
                                    $to = $log->metadata['to'] ?? null;
                                @endphp
                                @if ($from || $to)
                                    · {{ $statusLabels[$from] ?? $from ?? '—' }}
                                    → {{ $statusLabels[$to] ?? $to ?? '—' }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card-body>
    </x-ui.card>

</x-layouts.panel>
