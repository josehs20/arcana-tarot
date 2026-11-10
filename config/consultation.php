<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Saldo mínimo para iniciar uma consulta
    |--------------------------------------------------------------------------
    |
    | O aceite exige saldo suficiente para pelo menos N minutos ao preço
    | vigente no servidor. O valor em centavos é calculado por
    | ConsultationPricing; nenhum valor fixo de negócio fica no código.
    |
    */

    'minimum_balance_minutes' => max(1, (int) env('CONSULTATION_MINIMUM_BALANCE_MINUTES', 1)),

    /*
    |--------------------------------------------------------------------------
    | Expiração de solicitações pendentes
    |--------------------------------------------------------------------------
    |
    | Uma solicitação que não for aceita/recusada dentro deste prazo é
    | encerrada com status "expired". A rotina roda no comando
    | consultations:expire-requests.
    |
    */

    'request_ttl_minutes' => max(1, (int) env('CONSULTATION_REQUEST_TTL_MINUTES', 10)),

    /*
    |--------------------------------------------------------------------------
    | Presença do atendente
    |--------------------------------------------------------------------------
    |
    | O encerramento só mantém o atendente online se houver sinal de presença
    | recente (last_seen_at). Sem sinal recente, ele volta para offline. Esta
    | janela controla apenas a disponibilidade do atendente; a interrupção de
    | consultas inativas usa inactivity_timeout_minutes somado à tolerância de
    | reconexão (veja consultations:interrupt-disconnected).
    |
    */

    'presence_timeout_minutes' => max(1, (int) env('CONSULTATION_PRESENCE_TIMEOUT_MINUTES', 15)),

    'last_seen_touch_seconds' => max(5, (int) env('CONSULTATION_LAST_SEEN_TOUCH_SECONDS', 30)),

    /*
    |--------------------------------------------------------------------------
    | Inatividade do atendimento
    |--------------------------------------------------------------------------
    |
    | A presença do navegador é confirmada por batimento, mas atividade é o que
    | mantém a conversa aberta. Sem interação de nenhum dos participantes pelo
    | tempo configurado (mais a tolerância de reconexão), o comando
    | consultations:interrupt-disconnected encerra e liquida o atendimento com
    | o motivo auditável "inactivity". A administração pode sobrescrever os
    | dois valores na tela de segurança do chat.
    |
    */

    'inactivity_timeout_minutes' => max(1, (int) env('CONSULTATION_INACTIVITY_TIMEOUT_MINUTES', 5)),
    'reconnection_tolerance_seconds' => max(0, (int) env('CONSULTATION_RECONNECTION_TOLERANCE_SECONDS', 90)),
    'activity_ping_seconds' => max(5, (int) env('CONSULTATION_ACTIVITY_PING_SECONDS', 20)),
    'navigation_warning_seconds' => max(5, (int) env('CONSULTATION_NAVIGATION_WARNING_SECONDS', 60)),

    /*
    |--------------------------------------------------------------------------
    | Aviso de saldo baixo
    |--------------------------------------------------------------------------
    |
    | Quando o contador regressivo do servidor chega a este limite, o cliente
    | recebe um aviso na tela de atendimento com o caminho para comprar
    | créditos. É só um alerta de interface: a cobrança e o encerramento por
    | saldo insuficiente continuam decididos no servidor.
    |
    */

    'low_balance_warning_minutes' => max(1, (int) env('CONSULTATION_LOW_BALANCE_WARNING_MINUTES', 5)),

    /*
    |--------------------------------------------------------------------------
    | Chat persistente
    |--------------------------------------------------------------------------
    |
    | Limites de conteúdo, paginação e frequência do MVP. O texto é sempre
    | gravado pelo servidor com o remetente autenticado; cartas usam snapshots
    | privados e não aceitam caminhos ou metadados enviados pelo navegador.
    |
    */

    'message_max_length' => max(1, (int) env('CONSULTATION_MESSAGE_MAX_LENGTH', 2000)),
    'message_page_size' => max(1, (int) env('CONSULTATION_MESSAGE_PAGE_SIZE', 25)),
    'message_rate_per_minute' => max(1, (int) env('CONSULTATION_MESSAGE_RATE_PER_MINUTE', 30)),
    'card_rate_per_minute' => max(1, (int) env('CONSULTATION_CARD_RATE_PER_MINUTE', 15)),

    /*
    |--------------------------------------------------------------------------
    | Modalidades do atendimento
    |--------------------------------------------------------------------------
    |
    | Texto está sempre disponível. Áudio e vídeo só podem ser oferecidos
    | quando a infraestrutura de mídia está habilitada e configurada. Quando
    | desabilitados, o servidor recusa a solicitação — a interface também
    | oculta a opção, mas a validação nunca depende do navegador.
    |
    */

    'modalities' => [
        'text' => ['enabled' => true],
        'audio' => ['enabled' => filter_var(env('CONSULTATION_AUDIO_ENABLED', true), FILTER_VALIDATE_BOOL)],
        'video' => ['enabled' => filter_var(env('CONSULTATION_VIDEO_ENABLED', true), FILTER_VALIDATE_BOOL)],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chamadas WebRTC (áudio e vídeo)
    |--------------------------------------------------------------------------
    |
    | A sinalização (oferta SDP, resposta e candidatos ICE) trafega por HTTP
    | autenticado e é replicada pelo canal privado da consulta quando o
    | Reverb está disponível. A mídia vai direto entre os navegadores.
    |
    | STUN resolve NAT simples. Redes simétricas exigem um servidor TURN
    | próprio; configure CONSULTATION_ICE_SERVERS (JSON) em produção.
    |
    */

    'web_rtc' => [
        'ice_servers' => (static function (): array {
            $configured = env('CONSULTATION_ICE_SERVERS');

            if (is_string($configured) && trim($configured) !== '') {
                $decoded = json_decode($configured, true);

                if (is_array($decoded) && $decoded !== []) {
                    return $decoded;
                }
            }

            return [['urls' => 'stun:stun.l.google.com:19302']];
        })(),
        'ring_timeout_seconds' => max(15, (int) env('CONSULTATION_CALL_RING_TIMEOUT_SECONDS', 90)),
        'signal_poll_ms' => max(1000, (int) env('CONSULTATION_CALL_SIGNAL_POLL_MS', 2500)),
        'signal_max_bytes' => max(1024, (int) env('CONSULTATION_CALL_SIGNAL_MAX_BYTES', 65536)),
        'signal_max_per_call' => max(20, (int) env('CONSULTATION_CALL_SIGNAL_MAX_PER_CALL', 400)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entrega em tempo real
    |--------------------------------------------------------------------------
    |
    | Com Reverb disponível o navegador recebe eventos pelo WebSocket. Sem
    | processo persistente — hospedagem compartilhada — as mesmas leituras de
    | recuperação (mensagens por cursor e feed do painel) passam a ser
    | consultadas em intervalos curtos. Os valores abaixo são publicados nas
    | telas e lidos pelos scripts; a aba oculta usa o intervalo maior para não
    | gastar requisições sem ninguém olhando, e o backoff cresce em caso de erro.
    |
    */

    'realtime' => [
        'websocket_enabled' => filter_var(env('CONSULTATION_WEBSOCKET_ENABLED', true), FILTER_VALIDATE_BOOL),

        'poll' => [
            'chat_interval_ms' => max(1000, (int) env('CONSULTATION_POLL_CHAT_MS', 2000)),
            'chat_hidden_interval_ms' => max(3000, (int) env('CONSULTATION_POLL_CHAT_HIDDEN_MS', 10000)),
            'panel_interval_ms' => max(1000, (int) env('CONSULTATION_POLL_PANEL_MS', 3000)),
            'panel_hidden_interval_ms' => max(3000, (int) env('CONSULTATION_POLL_PANEL_HIDDEN_MS', 15000)),
            'max_backoff_ms' => max(5000, (int) env('CONSULTATION_POLL_MAX_BACKOFF_MS', 30000)),
        ],

        // Limites de leitura por participante. O polling do chat roda a cada
        // 2 s (30/min) e o do painel a cada 3 s (20/min), com folga para
        // recargas e reconexões.
        'message_feed_rate_per_minute' => max(10, (int) env('CONSULTATION_MESSAGE_FEED_RATE_PER_MINUTE', 90)),
        'panel_feed_rate_per_minute' => max(10, (int) env('CONSULTATION_PANEL_FEED_RATE_PER_MINUTE', 60)),
    ],

];
