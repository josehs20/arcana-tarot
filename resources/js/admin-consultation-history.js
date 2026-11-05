import { createPoller, readMilliseconds } from './polling';

/*
 * Atualização automática do histórico no painel administrativo.
 *
 * A consulta ativa continua recebendo mensagens e ocorrências, então a tela lê
 * o endpoint de histórico em intervalos curtos. O transporte WebSocket não é
 * usado aqui de propósito: a leitura administrativa passa pela política
 * `viewAdminHistory` e precisa de auditoria a cada acesso, o que só acontece em
 * requisições HTTP autenticadas.
 *
 * Cursor: a tela guarda o maior id de mensagem e de ocorrência já exibidos e
 * pede apenas o que veio depois. Os itens chegam do mais novo para o mais
 * antigo, então cada lote é inserido no topo, mantendo a ordem da página.
 */

const state = {
    poller: null,
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function setStatus(root, label, variant) {
    const badge = root.querySelector('[data-history-status]');

    if (!badge) {
        return;
    }

    badge.classList.remove('bg-secondary', 'bg-success', 'bg-warning', 'bg-danger');
    badge.classList.add(variant);
    badge.textContent = label;
}

function buildUrl(root) {
    const url = new URL(root.dataset.historyUrl, window.location.origin);

    url.searchParams.set('after_message_id', root.dataset.historyLastMessage || '0');
    url.searchParams.set('after_incident_id', root.dataset.historyLastIncident || '0');

    return url;
}

/**
 * Insere o HTML novo no topo da linha do tempo.
 *
 * O partial é o mesmo da página, então o mascaramento e o formulário de revisão
 * já vêm prontos. `insertAdjacentHTML` é seguro aqui porque o conteúdo é
 * gerado no servidor com escape padrão do Blade.
 */
function prependEntries(root, html) {
    const timeline = root.querySelector('[data-history-timeline]');

    if (!timeline || html.trim() === '') {
        return false;
    }

    timeline.classList.remove('d-none');

    const emptyState = root.querySelector('.empty-state');

    if (emptyState) {
        emptyState.classList.add('d-none');
    }

    timeline.insertAdjacentHTML('afterbegin', html);

    return true;
}

async function refresh(root) {
    const response = await fetch(buildUrl(root), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
    });

    // Sessão expirada ou acesso revogado: insistir só gera 403 em sequência.
    if (response.status === 401 || response.status === 403 || response.status === 404) {
        state.poller?.stop();
        setStatus(root, 'Atualização interrompida', 'bg-danger');

        return;
    }

    if (!response.ok) {
        throw new Error(`Falha ao ler o histórico (${response.status}).`);
    }

    const payload = await response.json();
    const added = prependEntries(root, payload.html ?? '');

    if (payload.last_message_id !== undefined) {
        root.dataset.historyLastMessage = String(payload.last_message_id);
    }

    if (payload.last_incident_id !== undefined) {
        root.dataset.historyLastIncident = String(payload.last_incident_id);
    }

    setStatus(root, added ? 'Novos registros' : 'Atualização automática ativa', 'bg-success');
}

function boot() {
    const root = document.querySelector('[data-admin-history]');

    if (!root || root.dataset.historyLive !== '1' || !root.dataset.historyUrl) {
        return;
    }

    state.poller = createPoller({
        intervalMs: readMilliseconds(root.dataset.historyInterval, 5000),
        hiddenIntervalMs: readMilliseconds(root.dataset.historyHiddenInterval, 20000),
        maxBackoffMs: readMilliseconds(root.dataset.historyBackoff, 30000),
        run: () => refresh(root),
        immediate: false,
    });

    state.poller.start();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
