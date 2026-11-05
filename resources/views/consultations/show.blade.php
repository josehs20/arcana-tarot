@php
    $status = $consultation->status;
    $backRoute = $isAdmin
        ? route('admin.consultations.index')
        : ($isClient ? route('client.dashboard') : route('attendant.dashboard'));
    $pricePerMinuteCents = $consultation->pricePerMinuteCents();
    $modality = $consultation->modality;
    $attendantName = $consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name;
@endphp

<x-layouts.panel :variant="$layoutVariant" :title="'Consulta #' . $consultation->id . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Consulta #' . $consultation->id"
        :subtitle="$isAdmin
            ? 'Visão administrativa do atendimento.'
            : ($isClient
                ? 'Conversa com ' . $attendantName . ' · acompanhe o andamento abaixo.'
                : 'Acompanhe o andamento da sua consulta.')"
    >
        <x-slot name="actions">
            <span id="consultation-status-summary" class="d-inline-flex align-items-center gap-2">
                <x-ui.badge variant="gold">
                    <x-ui.icon :name="$modality->icon()" width="13" height="13" />
                    {{ $modality->label() }}
                </x-ui.badge>
                <x-ui.status-badge :status="$status->value" :label="$status->label()" />
            </span>
            <a class="btn btn-ghost btn-sm" href="{{ $backRoute }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            {{--
                O cliente já sabe com quem está falando: o nome do atendente
                aparece no subtítulo. Por isso o cartão de participantes só é
                exibido para atendente e administração, deixando a visão do
                cliente focada em andamento, cobrança e conversa.
            --}}
            @unless ($isClient)
                <x-ui.card class="mb-4">
                    <x-ui.card-header>
                        <h2 class="h6 mb-0">Participantes</h2>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        <dl class="row mb-0">
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Cliente</dt>
                            <dd class="col-sm-9">
                                {{ $consultation->client->name }}
                                @if ($isAdmin)
                                    <span class="d-block form-text">{{ $consultation->client->email }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3 text-ivory-muted fw-normal">Atendente</dt>
                            <dd class="col-sm-9">
                                {{ $attendantName }}
                                @if ($isAdmin)
                                    <span class="d-block form-text">{{ $consultation->attendant->email }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3 text-ivory-muted fw-normal">Modalidade</dt>
                            <dd class="col-sm-9">
                                <x-ui.badge variant="gold">{{ $modality->label() }}</x-ui.badge>
                                <span class="form-text d-block">{{ $modality->description() }}</span>
                            </dd>

                            <dt class="col-sm-3 text-ivory-muted fw-normal">Sua função</dt>
                            <dd class="col-sm-9">
                                @if ($isAdmin)
                                    Administração
                                @elseif ($isClient)
                                    Cliente
                                @else
                                    Atendente
                                @endif
                            </dd>
                        </dl>
                    </x-ui.card-body>
                </x-ui.card>
            @endunless

            {{--
                `data-consultation-watch` diz ao canal privado do usuário qual
                consulta esta tela está acompanhando. Quando ela deixa de ser
                aberta no servidor (recusa, cancelamento, encerramento) o modo
                polling recarrega a página para exibir o estado final.
            --}}
            <x-ui.card id="consultation-progress" data-consultation-watch="{{ $consultation->id }}">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Andamento</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    {{--
                        Fica dentro do cartão de andamento porque `chat.js`
                        substitui `#consultation-progress` a cada transição de
                        estado: assim o aviso aparece mesmo sem recarregar a
                        página. A recusa é definitiva e encerra o chat.
                    --}}
                    @if ($status === \App\Enums\ConsultationStatus::Rejected)
                        <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                            @if ($isClient)
                                O atendente recusou esta solicitação: o chat foi encerrado e nenhum crédito foi debitado.
                                Escolha outro atendente para solicitar uma nova consulta.
                                <a class="alert-link" href="{{ route('attendants.index') }}">Ver atendentes</a>
                            @else
                                Esta solicitação foi recusada e o chat está encerrado. Nenhum crédito foi debitado.
                            @endif
                        </x-ui.alert>
                    @endif

                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-ivory-muted fw-normal">Status</dt>
                        <dd class="col-sm-9">
                            <x-ui.status-badge :status="$status->value" :label="$status->label()" />
                        </dd>

                        <dt class="col-sm-3 text-ivory-muted fw-normal">Solicitada em</dt>
                        <dd class="col-sm-9">{{ $consultation->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        <dt class="col-sm-3 text-ivory-muted fw-normal">Iniciada em</dt>
                        <dd class="col-sm-9">
                            {{ $consultation->started_at?->format('d/m/Y H:i') ?? ($status === \App\Enums\ConsultationStatus::Requested ? 'Aguardando aceite' : '—') }}
                        </dd>

                        @if ($consultation->started_at !== null)
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Tempo de sessão</dt>
                            <dd class="col-sm-9">
                                <span
                                    class="font-monospace"
                                    data-consultation-timer
                                    data-started-at="{{ $consultation->started_at->toIso8601String() }}"
                                    data-ended-at="{{ $consultation->ended_at?->toIso8601String() ?? '' }}"
                                >00:00</span>
                                <span class="form-text d-block">
                                    Contador informativo. A cobrança por minuto iniciado é processada no servidor.
                                </span>
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-ivory-muted fw-normal">Encerrada em</dt>
                        <dd class="col-sm-9">{{ $consultation->ended_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        @if ($consultation->ended_reason)
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Motivo</dt>
                            <dd class="col-sm-9">{{ $consultation->ended_reason->label() }}</dd>
                        @endif

                        @if ($pricePerMinuteCents !== null)
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Preço aplicado</dt>
                            <dd class="col-sm-9">
                                {{ \App\Support\Money::format($pricePerMinuteCents) }} por minuto
                                @if ($isClient)
                                    <span class="form-text d-block">Cobrança por minuto iniciado, processada no servidor.</span>
                                @endif
                            </dd>
                        @endif

                        @if (($isClient || $isAdmin) && $consultation->billed_minutes > 0)
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Total cobrado</dt>
                            <dd class="col-sm-9">
                                {{ \App\Support\Money::format($consultation->billed_minutes * (int) $pricePerMinuteCents) }}
                                <span class="form-text d-block">
                                    {{ $consultation->billed_minutes }} minuto(s) × {{ \App\Support\Money::format((int) $pricePerMinuteCents) }}
                                </span>
                            </dd>
                        @endif

                        @if ($isAdmin || $isAttendant)
                            <dt class="col-sm-3 text-ivory-muted fw-normal">Comissão aplicada</dt>
                            <dd class="col-sm-9">{{ $consultation->commission_rate_bps !== null ? \App\Support\Rate::formatBps($consultation->commission_rate_bps) : '—' }}</dd>
                        @endif
                    </dl>

                    @if ($status === \App\Enums\ConsultationStatus::Requested)
                        <p class="form-text mt-3 mb-0">
                            @if ($isClient)
                                Sua solicitação está aguardando o aceite do atendente.
                            @else
                                Aceite ou recuse a solicitação para liberar ou encerrar o atendimento.
                            @endif
                        </p>
                    @elseif ($status === \App\Enums\ConsultationStatus::Active)
                        <p class="form-text mt-3 mb-0">
                            @if ($modality->allowsChat())
                                A consulta está em andamento. Use a conversa abaixo para falar com
                                {{ $isClient ? 'o atendente' : 'o cliente' }}.
                            @else
                                A consulta está em andamento. Use os controles da chamada de
                                {{ \Illuminate\Support\Str::lower($modality->label()) }} abaixo.
                            @endif
                        </p>
                    @else
                        <p class="form-text mt-3 mb-0">
                            Esta consulta está encerrada e não aceita novas ações. O histórico permanece disponível.
                        </p>
                    @endif
                </x-ui.card-body>
            </x-ui.card>
        </div>



        <div class="col-lg-4">
            {{-- O cartão pressupõe "cliente x atendente"; na visão do administrador não há lado próprio. --}}
            @unless ($isAdmin)
                @include('consultations.partials.parties')
            @endunless

            <x-ui.card id="consultation-actions">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Ações</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    @if ($isClient && $status === \App\Enums\ConsultationStatus::Requested)
                        @can('cancel', $consultation)
                            <form method="POST" action="{{ route('client.consultations.cancel', $consultation) }}">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" variant="ghost" block>
                                    Cancelar solicitação
                                </x-ui.button>
                            </form>
                        @endcan
                    @endif

                    @if ($isAttendant && $status === \App\Enums\ConsultationStatus::Requested)
                        @can('accept', $consultation)
                            <form method="POST" action="{{ route('attendant.consultations.accept', $consultation) }}" class="mb-2">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" variant="primary" block>
                                    Aceitar consulta
                                </x-ui.button>
                            </form>
                        @endcan

                        @can('reject', $consultation)
                            <form method="POST" action="{{ route('attendant.consultations.reject', $consultation) }}">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" variant="ghost" block>
                                    Recusar solicitação
                                </x-ui.button>
                            </form>
                        @endcan
                    @endif

                    @if ($status === \App\Enums\ConsultationStatus::Active)
                        @can('end', $consultation)
                            <form method="POST" action="{{ route('consultations.end', $consultation) }}">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" variant="danger" block>
                                    Encerrar consulta
                                </x-ui.button>
                            </form>
                            <p class="form-text mt-3 mb-0">
                                O encerramento é definitivo e fica registrado no histórico.
                            </p>
                        @endcan
                    @endif

                    @if ($status->isFinal())
                        <p class="text-ivory-dim mb-0">Nenhuma ação disponível para consultas encerradas.</p>
                    @endif
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    @if ($canViewMessages)
        <div class="row g-4 mt-1">
            <div class="col-12">
                @include('consultations.partials.chat')
            </div>
        </div>
    @elseif ($consultation->requiresCall())
        <div class="row g-4 mt-1">
            <div class="col-12">
                @include('consultations.partials.call')
            </div>
        </div>
    @elseif ($isAdmin)
        <x-ui.alert type="info" :dismissible="false" class="mt-4">
            A conversa entre cliente e atendente é privada e não é exibida na visão administrativa.
        </x-ui.alert>
    @endif

    @if ($canLeaveTestimonial)
        @include('consultations.partials.testimonial-modal', [
            'consultation' => $consultation,
            'attendantName' => $attendantName,
            'modalId' => 'testimonial-modal-'.$consultation->getKey(),
            'autoOpen' => true,
        ])
    @endif
</x-layouts.panel>

