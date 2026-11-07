{{--
    Avisos em tempo real do painel autenticado.

    O layout inclui este bloco em toda tela de cliente e de atendente (nunca na
    administração). Antes o canal privado só existia no dashboard, então um
    pedido feito enquanto o atendente navegava pelo histórico, pelo perfil ou
    por uma consulta aberta não abria o modal nem tocava o toque de chamada.

    O bloco apenas publica os endpoints; `resources/js/user-channel.js` abre o
    canal `user.{id}`, abre o modal de aceite/recusa e toca o som configurado
    (sintetizado no navegador ou MP3 — o da plataforma ou o enviado pela
    administração). Nenhuma decisão acontece no navegador: aceitar/recusar
    chamam as rotas do servidor.
--}}
@php
    $realtimeUser = auth()->user();
    $realtimeIsAttendant = $realtimeUser?->isAttendant() ?? false;
    $realtimeProfile = $realtimeIsAttendant ? $realtimeUser->attendantProfile : null;
    $realtimeSound = $realtimeProfile?->effectiveRequestSound();
    $realtimeRequestSound = $realtimeSound?->value ?? 'siren';
    /*
     * O bloco publica a URL do arquivo quando o toque depende de download: o
     * MP3 versionado da plataforma (`olha-o-gas`) ou o enviado pela
     * administração (`custom`). Toques sintetizados não enviam URL.
     */
    $realtimeRequestSoundUrl = $realtimeProfile?->effectiveRequestSoundUrl();

    /*
     * Mesma decisão do chat: com WebSocket o aviso vem pelo canal privado; sem
     * ele, `painel/eventos` é lido em intervalos curtos e devolve a fila de
     * solicitações do atendente e a consulta aberta do usuário.
     */
    $realtimeMode = \App\Support\Realtime\RealtimeMode::current();
    $realtimePollIntervals = config('consultation.realtime.poll');

    /*
     * Consulta ativa do usuário: alimenta o aviso "Voltar ao atendimento" em
     * qualquer tela do painel fora do chat. A escolha é feita no servidor
     * (`ActiveConsultationResolver`), nunca a partir de um ID da URL.
     */
    $realtimeActiveConsultation = $realtimeUser
        ? app(\App\Support\ActiveConsultationResolver::class)->forUser($realtimeUser)
        : null;

    $realtimeActiveConsultation?->loadMissing('attendant.attendantProfile');
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
        {{--
            Estados finais recarregam a tela para cliente e atendente: é assim
            que a recusa, o cancelamento e o encerramento aparecem na hora, sem
            deixar a conversa em um estado que já não existe no servidor.
        --}}
        data-refresh-on-final="1"
        @if ($realtimeIsAttendant)
            data-pending-url="{{ route('attendant.requests.pending') }}"
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

    @include('layouts.partials.active-consultation-banner', ['consultation' => $realtimeActiveConsultation])
@endif
