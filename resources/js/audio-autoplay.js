/**
 * Descoberta de permissão de autoplay — sem `AudioContext`.
 *
 * Criar um `AudioContext` sem interação do usuário faz o Chrome escrever
 * "The AudioContext was not allowed to start" no console e deixa o contexto
 * suspenso: nenhum som toca e a página fica com ruído de log a cada visita.
 *
 * Um `<audio>` com poucos milissegundos de silêncio responde a mesma pergunta
 * pela mesma política do navegador, sem aviso nenhum: se `play()` resolver, o
 * autoplay está liberado (o Chrome libera por engajamento com o site) e o
 * contexto criado logo depois nasce em `running`; se rejeitar, esperamos o
 * primeiro gesto, que é o caminho garantido.
 *
 * O resultado positivo fica em cache para sempre; o negativo expira, porque um
 * clique no painel muda a resposta do navegador.
 */
const SILENT_WAV = 'data:audio/wav;base64,UklGRuwAAABXQVZFZm10IBAAAAABAAEAIlYAAESsAAACABAAZGF0YcgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==';

const NEGATIVE_CACHE_MS = 15000;

let allowed = false;
let pending = null;
let checkedAt = 0;

function probe() {
    return new Promise((resolve) => {
        if (typeof Audio !== 'function') {
            resolve(false);

            return;
        }

        const audio = new Audio(SILENT_WAV);
        audio.volume = 0.001;
        audio.setAttribute('aria-hidden', 'true');

        const finish = (result) => {
            try {
                audio.pause();
            } catch {
                // Elemento já descartado.
            }

            resolve(result);
        };

        let playback = null;

        try {
            playback = audio.play();
        } catch {
            finish(false);

            return;
        }

        if (playback && typeof playback.then === 'function') {
            playback.then(() => finish(true)).catch(() => finish(false));

            return;
        }

        finish(false);
    });
}

/**
 * Resolve `true` quando o navegador deixa a página tocar som sem gesto.
 */
export function autoplayAllowed() {
    if (allowed) {
        return Promise.resolve(true);
    }

    if (checkedAt > 0 && Date.now() - checkedAt < NEGATIVE_CACHE_MS) {
        return Promise.resolve(false);
    }

    if (pending) {
        return pending;
    }

    pending = probe().then((result) => {
        allowed = result;
        checkedAt = Date.now();
        pending = null;

        return result;
    });

    return pending;
}

/**
 * Registra que o navegador já liberou o som nesta página (gesto ou execução
 * confirmada), evitando novas sondagens.
 */
export function markAutoplayAllowed() {
    allowed = true;
    checkedAt = Date.now();
}
