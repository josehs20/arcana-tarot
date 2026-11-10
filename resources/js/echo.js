import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echo = null;

/**
 * Modo de entrega escolhido pelo servidor (`App\Support\Realtime\RealtimeMode`).
 *
 * O layout publica `<meta name="realtime-mode">`; os blocos que já conhecem o
 * modo (chat e avisos do painel) repetem o valor em `data-realtime-mode`.
 * Sem nenhum marcador assumimos `socket` para não tirar o tempo real de páginas
 * antigas que ainda não publicam a decisão.
 */
function realtimeMode() {
    const meta = document.querySelector('meta[name="realtime-mode"]')?.content;
    const marker = meta || document.querySelector('[data-realtime-mode]')?.dataset.realtimeMode;

    return marker === 'poll' ? 'poll' : 'socket';
}

/**
 * Cria o Echo sob demanda, apenas em páginas que realmente usam tempo real.
 * A configuração pública vem do Vite; nenhum segredo do Reverb é exposto.
 *
 * Em modo `poll` nada é criado: os canais não têm quem entregue os eventos
 * (não existe processo `reverb:start`), então abrir a conexão só produzia
 * tentativas repetidas de WebSocket contra a porta 8080 e mensagens de erro no
 * console. Quem chama recebe `null` e segue pela leitura periódica que já
 * existe em `chat.js`, `user-channel.js`, `consultation-workspace.js` e
 * `consultation-call.js`.
 */
window.initializeEcho = () => {
    if (realtimeMode() === 'poll') {
        return null;
    }

    if (echo !== null) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        return null;
    }

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const isSecure = scheme === 'https';

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: isSecure,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });

    window.Echo = echo;

    return echo;
};
