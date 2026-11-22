/**
 * Heartbeat de acessos.
 *
 * O servidor só sabe que a página foi aberta; sem este batimento uma leitura
 * de 20 minutos apareceria com duração de poucos segundos. Enquanto a aba está
 * visível, um POST silencioso renova `last_activity_at`. Ao ocultar ou sair, um
 * último aviso via `sendBeacon` registra o instante da saída.
 *
 * Se a página não declara o endpoint (configuração desligada), o script não faz
 * nada. Falhas são ignoradas: telemetria nunca deve atrapalhar a navegação.
 */
const endpoint = document.querySelector('meta[name="visit-heartbeat-endpoint"]')?.content;
const intervalMeta = document.querySelector('meta[name="visit-heartbeat-interval"]')?.content;
const interval = Math.max(15, Number(intervalMeta) || 60) * 1000;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

let timer = null;

const ping = () => {
    if (!endpoint || document.visibilityState === 'hidden') {
        return;
    }

    window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
    }).catch(() => {
        // Sessão expirada, rede instável ou endpoint desligado: sem efeito.
    });
};

const beacon = () => {
    if (!endpoint || typeof navigator.sendBeacon !== 'function') {
        return;
    }

    const payload = new FormData();
    payload.append('_token', csrfToken);
    navigator.sendBeacon(endpoint, payload);
};

const start = () => {
    if (timer !== null) {
        return;
    }

    timer = window.setInterval(ping, interval);
};

const stop = () => {
    if (timer === null) {
        return;
    }

    window.clearInterval(timer);
    timer = null;
};

if (endpoint) {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            stop();
            beacon();

            return;
        }

        ping();
        start();
    });

    window.addEventListener('pagehide', () => {
        stop();
        beacon();
    });

    if (document.visibilityState === 'visible') {
        ping();
        start();
    }
}
