/**
 * Ritmo das leituras HTTP quando não existe WebSocket.
 *
 * Em hospedagem compartilhada o Reverb não tem processo persistente, então as
 * telas leem periodicamente os mesmos endpoints usados na recuperação após
 * reconexão. Este agendador é deliberadamente conservador:
 *  - encadeia `setTimeout` (nunca `setInterval`), para que um ciclo lento não
 *    acumule requisições;
 *  - nunca dispara duas leituras ao mesmo tempo: se a anterior não terminou, o
 *    próximo ciclo apenas espera;
 *  - aba oculta usa o intervalo maior, e voltar para a aba lê na hora;
 *  - sem conexão (`navigator.onLine === false`) as leituras ficam pausadas até
 *    o evento `online`;
 *  - falhas (erro de rede, 429, 5xx) dobram a espera até `maxBackoffMs`, e um
 *    acerto volta ao ritmo normal;
 *  - um pequeno sorteio (±15%) espalha as requisições de várias abas, evitando
 *    que todas cheguem no mesmo segundo no servidor compartilhado.
 *
 * `run` deve devolver uma promessa: resolver significa "leitura concluída",
 * rejeitar significa "tentar de novo mais tarde". Quem chama decide o que
 * fazer com o conteúdo; o agendador não interpreta dados de negócio.
 */

const DEFAULT_MAX_BACKOFF_MS = 30000;
const MAX_BACKOFF_DOUBLINGS = 6;
const JITTER_RATIO = 0.15;

/**
 * Lê um intervalo publicado em `data-*`, caindo no padrão quando o atributo
 * está ausente ou inválido (o Blade sempre escreve inteiros, mas uma edição
 * manual no HTML não pode zerar o ritmo das leituras).
 */
export function readMilliseconds(value, fallback) {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

export function createPoller({
    intervalMs,
    hiddenIntervalMs = intervalMs,
    maxBackoffMs = DEFAULT_MAX_BACKOFF_MS,
    run,
    immediate = true,
} = {}) {
    const state = {
        timer: null,
        running: false,
        stopped: true,
        failures: 0,
    };

    function jitter(delay) {
        const spread = delay * JITTER_RATIO;

        return Math.max(1000, Math.round(delay + (Math.random() * 2 - 1) * spread));
    }

    function baseDelay() {
        return document.visibilityState === 'hidden' ? hiddenIntervalMs : intervalMs;
    }

    function nextDelay() {
        if (state.failures === 0) {
            return jitter(baseDelay());
        }

        const backup = baseDelay() * 2 ** Math.min(state.failures, MAX_BACKOFF_DOUBLINGS);

        return Math.min(maxBackoffMs, jitter(backup));
    }

    function schedule(delay) {
        window.clearTimeout(state.timer);

        if (state.stopped) {
            return;
        }

        state.timer = window.setTimeout(tick, delay);
    }

    async function tick() {
        state.timer = null;

        if (state.stopped) {
            return;
        }

        // Aba sem rede: aguarda o evento `online` em vez de acumular erros.
        if (navigator.onLine === false) {
            state.failures = 0;
            schedule(hiddenIntervalMs);

            return;
        }

        if (state.running) {
            schedule(baseDelay());

            return;
        }

        state.running = true;

        try {
            await run();
            state.failures = 0;
        } catch {
            state.failures = Math.min(state.failures + 1, MAX_BACKOFF_DOUBLINGS);
        } finally {
            state.running = false;
        }

        schedule(nextDelay());
    }

    function wake() {
        if (state.stopped || state.running) {
            return;
        }

        state.failures = 0;
        window.clearTimeout(state.timer);
        void tick();
    }

    function handleVisibilityChange() {
        if (document.visibilityState === 'hidden') {
            schedule(hiddenIntervalMs);

            return;
        }

        wake();
    }

    return {
        start() {
            if (!state.stopped) {
                return;
            }

            state.stopped = false;
            state.failures = 0;

            document.addEventListener('visibilitychange', handleVisibilityChange);
            window.addEventListener('online', wake);

            if (immediate) {
                void tick();

                return;
            }

            schedule(baseDelay());
        },

        stop() {
            state.stopped = true;
            state.failures = 0;
            window.clearTimeout(state.timer);
            state.timer = null;
            document.removeEventListener('visibilitychange', handleVisibilityChange);
            window.removeEventListener('online', wake);
        },

        isRunning() {
            return !state.stopped;
        },
    };
}
