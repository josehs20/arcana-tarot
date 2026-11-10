@php
    $callStatus = $consultation->status;
    $callPayload = \App\Support\ConsultationCallPresenter::toArray($call);
    $isVideo = $consultation->modality?->requiresVideo() ?? false;
    $callConfig = $callConfig ?? [];

    /*
     * O partial é incluído pela tela da consulta, pela tela dedicada de
     * atendimento e por testes. O papel administrativo nem sempre vem no
     * payload; o fallback evita a variável indefinida na visão de auditoria.
     */
    $isAdmin = $isAdmin ?? false;
@endphp

<section
    id="call"
    class="call-panel"
    aria-labelledby="call-title"
    data-call
    data-consultation-id="{{ $consultation->id }}"
    data-user-id="{{ auth()->id() }}"
    data-role="{{ $isClient ? 'client' : ($isAttendant ? 'attendant' : 'admin') }}"
    data-modality="{{ $consultation->modality?->value }}"
    data-requires-video="{{ $isVideo ? '1' : '0' }}"
    data-status="{{ $callStatus->value }}"
    data-call='@json($callPayload, JSON_HEX_APOS | JSON_HEX_QUOT)'
    data-ice-servers='@json($callConfig['ice_servers'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)'
    data-poll-ms="{{ (int) ($callConfig['poll_ms'] ?? 2500) }}"
    data-ring-timeout="{{ (int) ($callConfig['ring_timeout_seconds'] ?? 90) }}"
    data-state-url="{{ route('consultations.call.state', $consultation) }}"
    data-request-url="{{ route('consultations.call.store', $consultation) }}"
    data-accept-url="{{ route('consultations.call.accept', $consultation) }}"
    data-reject-url="{{ route('consultations.call.reject', $consultation) }}"
    data-connected-url="{{ route('consultations.call.connected', $consultation) }}"
    data-end-url="{{ route('consultations.call.end', $consultation) }}"
    data-fail-url="{{ route('consultations.call.fail', $consultation) }}"
    data-signal-url="{{ route('consultations.call.signal', $consultation) }}"
>
    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0" id="call-title">
                Chamada de {{ \Illuminate\Support\Str::lower($consultation->modality?->label() ?? 'áudio') }}
            </h2>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-secondary" data-call-connection role="status" aria-live="polite">
                    Aguardando
                </span>
                <span class="badge bg-secondary" data-call-status role="status" aria-live="polite">
                    {{ $call?->status?->label() ?? 'Sem chamada' }}
                </span>
                @if ($consultation->pricePerMinuteCents() !== null)
                    <x-ui.badge variant="gold">
                        {{ $consultation->pricePerMinuteLabel() }} / minuto
                    </x-ui.badge>
                @endif
            </div>
        </x-ui.card-header>

        <x-ui.card-body>
            @if ($isAdmin)
                <x-ui.alert type="info" :dismissible="false">
                    A chamada acontece diretamente entre cliente e atendente (WebRTC). A administração
                    acompanha apenas os metadados da consulta.
                </x-ui.alert>
            @else
                <div class="alert alert-info py-2 px-3 mb-3 d-none" data-call-notice role="status" aria-live="polite"></div>

                <div @class(['call-stage', 'call-stage--video' => $isVideo, 'call-stage--audio' => ! $isVideo]) data-call-stage>
                    <video class="call-stage__remote" data-call-remote autoplay playsinline></video>

                    @if ($isVideo)
                        <div class="call-stage__local" data-call-local-wrapper>
                            <video class="call-stage__local-video" data-call-local autoplay playsinline muted></video>
                            <span class="call-stage__local-label">Você</span>
                        </div>
                    @endif

                    <div class="call-stage__placeholder" data-call-placeholder>
                        <span class="call-stage__pulse" aria-hidden="true">
                            <x-ui.icon :name="$consultation->modality?->icon() ?? 'phone'" width="34" height="34" />
                        </span>
                        <p class="call-stage__hint" data-call-hint>
                            @if ($callStatus === \App\Enums\ConsultationStatus::Active)
                                Pronto para iniciar a chamada.
                            @elseif ($callStatus === \App\Enums\ConsultationStatus::Requested)
                                A chamada será liberada quando o atendente aceitar a consulta.
                            @else
                                Esta consulta está encerrada.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="call-controls" role="group" aria-label="Controles da chamada">
                    @if ($isClient)
                        <button type="button" class="btn btn-primary" data-call-request @disabled($callStatus !== \App\Enums\ConsultationStatus::Active || $call !== null)>
                            <x-ui.icon name="phone" width="16" height="16" />
                            Iniciar chamada
                        </button>
                    @endif

                    @if ($isAttendant)
                        <button type="button" class="btn btn-primary" data-call-accept hidden>
                            <x-ui.icon name="phone" width="16" height="16" />
                            Aceitar chamada
                        </button>
                        <button type="button" class="btn btn-ghost" data-call-reject hidden>
                            Recusar
                        </button>
                    @endif

                    <button type="button" class="btn btn-ghost" data-call-mute hidden aria-pressed="false">
                        <x-ui.icon name="mic" width="16" height="16" data-call-mute-icon />
                        <span data-call-mute-label>Silenciar microfone</span>
                    </button>

                    @if ($isVideo)
                        <button type="button" class="btn btn-ghost" data-call-camera hidden aria-pressed="false">
                            <x-ui.icon name="video" width="16" height="16" data-call-camera-icon />
                            <span data-call-camera-label>Desligar câmera</span>
                        </button>
                    @endif

                    <button type="button" class="btn btn-danger" data-call-hangup hidden>
                        <x-ui.icon name="phone-off" width="16" height="16" />
                        Encerrar chamada
                    </button>
                </div>

                <p class="form-text mt-3 mb-0">
                    A mídia de áudio e vídeo trafega diretamente entre os navegadores (WebRTC).
                    Se o microfone for negado pelo navegador, libere a permissão e tente novamente.
                    Encerrar ou recusar a chamada finaliza a consulta.
                </p>
            @endif
        </x-ui.card-body>
    </x-ui.card>
</section>
