<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suporte ao visitante
    |--------------------------------------------------------------------------
    |
    | O visitante é identificado por um token aleatório guardado apenas como
    | hash no banco. O token em texto claro fica num cookie HttpOnly e nunca
    | aparece em URLs, respostas JSON ou logs. Perder o cookie significa
    | perder o acesso à conversa — o histórico do painel permanece intacto.
    |
    */

    'cookie' => env('SUPPORT_COOKIE', 'arcana_support'),

    'cookie_lifetime_days' => max(1, (int) env('SUPPORT_COOKIE_LIFETIME_DAYS', 30)),

    'message_max_length' => max(200, (int) env('SUPPORT_MESSAGE_MAX_LENGTH', 2000)),

    /*
    |--------------------------------------------------------------------------
    | Histórico
    |--------------------------------------------------------------------------
    |
    | A primeira carga devolve as últimas mensagens da conversa. O widget
    | completa o restante por polling incremental (after_id), sempre na ordem
    | original e sem duplicar o que já está na tela.
    |
    */

    'history_limit' => max(20, (int) env('SUPPORT_HISTORY_LIMIT', 100)),

    'incremental_limit' => max(20, (int) env('SUPPORT_INCREMENTAL_LIMIT', 100)),

    /*
    |--------------------------------------------------------------------------
    | Painel administrativo
    |--------------------------------------------------------------------------
    |
    | Conversas encerradas ficam disponíveis para consulta por este período.
    | O expurgo definitivo é responsabilidade de um comando de manutenção.
    |
    */

    'closed_history_days' => max(1, (int) env('SUPPORT_CLOSED_HISTORY_DAYS', 30)),

    'closed_history_limit' => max(10, (int) env('SUPPORT_CLOSED_HISTORY_LIMIT', 50)),

    /*
    |--------------------------------------------------------------------------
    | Disponibilidade da equipe
    |--------------------------------------------------------------------------
    |
    | O painel envia um heartbeat enquanto a aba está visível. O visitante vê
    | "Equipe online" apenas se algum administrador bateu o ponto dentro desta
    | janela. O TTL é renovado a cada batimento.
    |
    */

    'presence_ttl_seconds' => max(30, (int) env('SUPPORT_PRESENCE_TTL_SECONDS', 120)),

    'presence_heartbeat_seconds' => max(15, (int) env('SUPPORT_PRESENCE_HEARTBEAT_SECONDS', 45)),

    /*
    |--------------------------------------------------------------------------
    | Intervalos de polling
    |--------------------------------------------------------------------------
    |
    | Usados somente quando o Reverb não está disponível (Hostoo). Com Reverb,
    | o widget continua consultando em intervalos maiores como rede de
    | segurança: eventos entregam na hora, o polling cobre reconexões.
    |
    */

    'polling' => [
        'visitor_messages_ms' => max(1000, (int) env('SUPPORT_POLL_VISITOR_MS', 4000)),
        'visitor_socket_backup_ms' => max(5000, (int) env('SUPPORT_POLL_VISITOR_BACKUP_MS', 15000)),
        'admin_conversations_ms' => max(1000, (int) env('SUPPORT_POLL_ADMIN_MS', 4000)),
        'admin_messages_ms' => max(1000, (int) env('SUPPORT_POLL_ADMIN_MESSAGES_MS', 2500)),
        'admin_socket_backup_ms' => max(5000, (int) env('SUPPORT_POLL_ADMIN_BACKUP_MS', 15000)),
    ],

];
