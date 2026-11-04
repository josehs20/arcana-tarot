<?php

namespace App\Support\Realtime;

/**
 * Define como o navegador recebe os avisos do servidor.
 *
 * Com um broadcaster WebSocket configurado (`BROADCAST_CONNECTION=reverb`),
 * o modo é `socket`: os eventos chegam pelo Reverb com o Echo. Quando não há
 * processo persistente — hospedagem compartilhada, por exemplo — o modo é
 * `poll`: as telas leem os mesmos endpoints de recuperação em intervalos
 * curtos. A decisão é feita no servidor e publicada em `data-realtime-mode`
 * para que o bundle compilado não dependa de variáveis `VITE_*`.
 */
final class RealtimeMode
{
    public const Socket = 'socket';

    public const Polling = 'poll';

    /**
     * Broadcasters que o front-end abre via Echo com WebSocket.
     *
     * Qualquer outro valor — inclusive `null`, `log` ou um driver inexistente —
     * é tratado como ausência de WebSocket: sem processo persistente, a leitura
     * periódica é a única entrega confiável.
     */
    private const WebSocketDrivers = ['reverb', 'pusher', 'ably'];

    public static function current(): string
    {
        if (! filter_var(config('consultation.realtime.websocket_enabled'), FILTER_VALIDATE_BOOL)) {
            return self::Polling;
        }

        return in_array(self::broadcaster(), self::WebSocketDrivers, true)
            ? self::Socket
            : self::Polling;
    }

    public static function isPolling(): bool
    {
        return self::current() === self::Polling;
    }

    private static function broadcaster(): string
    {
        $driver = config('broadcasting.default');

        return is_string($driver) ? strtolower(trim($driver)) : '';
    }
}
