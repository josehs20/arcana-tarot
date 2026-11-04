import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echo = null;

/**
 * Cria o Echo sob demanda, apenas em páginas que realmente usam tempo real.
 * A configuração pública vem do Vite; nenhum segredo do Reverb é exposto.
 */
window.initializeEcho = () => {
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
