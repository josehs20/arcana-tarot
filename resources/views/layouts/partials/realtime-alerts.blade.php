{{--
    Avisos em tempo real do painel autenticado.

    O layout inclui este bloco em toda tela de cliente e de atendente (nunca na
    administração). Antes o canal privado só existia no dashboard, então um
    pedido feito enquanto o atendente navegava pelo histórico, pelo perfil ou
    por uma consulta aberta não abria o modal nem tocava a sirene.

    O bloco apenas publica os endpoints; `resources/js/user-channel.js` abre o
    canal `user.{id}`, abre o modal de aceite/recusa e toca o som configurado
    (sirene, toque sintetizado ou MP3 enviado pela administração). Nenhuma
    decisão acontece no navegador: aceitar/recusar chamam as rotas do servidor.
--}}
@php
    $realtimeUser = auth()->user();
    $realtimeIsAttendant = $realtimeUser?->isAttendant() ?? false;
    $realtimeProfile = $realtimeIsAttendant ? $realtimeUser->attendantProfile : null;
    $realtimeSound = $realtimeProfile?->effectiveRequestSound();
    $realtimeRequestSound = $realtimeSound?->value ?? 'siren';
    $realtimeRequestSoundUrl = $realtimeSound?->isCustom()
        ? $realtimeProfile?->requestSoundUrl()
        : null;

    /*
     * Mesma decisão do chat: com WebSocket o aviso vem pelo canal privado; sem
     * ele, `painel/eventos` é lido em intervalos curtos e devolve a fila de
     * solicitações do atendente e a consulta aberta do usuário.
     */
    $realtimeMode = \App\Support\Realtime\RealtimeMode::current();
    $realtimePollIntervals = config('consultation.realtime.poll');
@endphp

@if ($realtimeUser && $realtimeUser->is_active)
    <div
        data-user-channel
        data-user-id="{{ $realtimeUser->id }}"
        data-events-url="{{ route('panel.events') }}"
        data-realtime-mode="{{ $realtimeMode }}"
        data-poll-interval-ms="{{ (int) $realtimePollIntervals['panel_interval_ms'] }}"
        data-poll-hidden-interval-ms="{{ (int) $realtimePollIntervals['panel_hidden_interval_ms'] }}"
        data-poll-max-backoff-ms="{{ (int) $realtimePollIntervals['max_backoff_ms'] }}"
        data-workspace-url="{{ route('consultations.workspace', ['consultation' => '__ID__']) }}"
        @if ($realtimeIsAttendant)
            data-pending-url="{{ route('attendant.requests.pending') }}"
            data-refresh-on-final="1"
            data-request-sound="{{ $realtimeRequestSound }}"
            @if ($realtimeRequestSoundUrl)
                data-request-sound-url="{{ $realtimeRequestSoundUrl }}"
            @endif
        @endif
        hidden
    ></div>

    @if ($realtimeIsAttendant)
        @include('layouts.partials.request-modal')
    @endif
@endif
