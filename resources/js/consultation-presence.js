/**
 * Presença, avisos de saída e compra de créditos no atendimento.
 *
 * O relógio oficial vive no servidor. Este arquivo só:
 *  - envia batimentos periódicos para marcar a sessão do navegador como viva;
 *  - marca atividade apenas em interações reais (abrir a tela, digitar, clicar,
 *    enviar mensagem), nunca no batimento automático, para que uma aba ociosa
 *    não mantenha a consulta aberta indefinidamente;
 *  - avisa antes de sair e, apenas no lado do atendente, depois de um período
 *    sem interação; quando o atendente confirma a saída no aviso, a consulta é
 *    encerrada;
 *  - atualiza saldo e presença com os valores devolvidos pelo servidor.
 *
 * Ao navegar para a compra de créditos, o link é liberado sem aviso, mas a
 * saída é registrada. A tela de créditos guarda o atendimento ativo na sessão e
 * oferece o retorno; o token de presença no sessionStorage permite reconectar
 * à mesma sessão quando o cliente voltar.
 */

import { Modal } from 'bootstrap';

import { clearIntentionalNavigation, hasIntentionalNavigation, markIntentionalNavigation } from './navigation-intent';

const ACTIVITY_FLUSH_MS = 5000;
const PRESENCE_LABELS = {
    online: 'Online',
    reconnecting: 'Reconectando',
    offline: 'Offline',
};
const PRESENCE_CLASSES = {
    online: 'bg-success',
    reconnecting: 'bg-warning text-dark',
    offline: 'bg-secondary',
};

const state = {
    root: null,
    consultationId: null,
    token: null,
    tokenKey: null,
    heartbeatTimer: null,
    warningTimer: null,
    lastActivityAt: 0,
    lastActivitySentAt: 0,
    sending: false,
    leaving: false,
    pendingNavigation: null,
    inactivityModal: null,
    navigationModal: null,
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function workspaceRoot() {
    return document.querySelector('[data-consultation-workspace]');
}

function heartbeatIntervalMs() {
    const seconds = Number(state.root?.dataset.activityPingSeconds ?? 20);

    return Math.max(5, Number.isFinite(seconds) ? seconds : 20) * 1000;
}

function warningDelayMs() {
    const seconds = Number(state.root?.dataset.navigationWarningSeconds ?? 20);

    return Math.max(5, Number.isFinite(seconds) ? seconds : 20) * 1000;
}

function isActive() {
    return state.root?.dataset.status === 'active';
}

function bootstrapModal(element) {
    if (!element) {
        return null;
    }

    return Modal.getOrCreateInstance(element);
}

function hideInactivityWarning() {
    state.inactivityModal?.hide();
}

function updatePresence(presence) {
    if (!presence) {
        return;
    }

    ['client', 'attendant'].forEach((role) => {
        const badge = state.root.querySelector(`[data-presence-role="${role}"]`);
        const rolePresence = presence[role];

        if (!badge || !rolePresence) {
            return;
        }

        const stateName = Object.hasOwn(PRESENCE_LABELS, rolePresence.state)
            ? rolePresence.state
            : 'offline';

        badge.textContent = PRESENCE_LABELS[stateName];
        badge.classList.remove('bg-success', 'bg-warning', 'bg-secondary', 'text-dark');
        badge.classList.add(...PRESENCE_CLASSES[stateName].split(' '));
    });
}

function updateCredits(credits, creditsUrl) {
    if (credits?.balance_label) {
        state.root.querySelectorAll('[data-credit-balance]').forEach((element) => {
            element.textContent = credits.balance_label;
        });
    }

    if (creditsUrl) {
        state.root.querySelectorAll('[data-consultation-credits-link]').forEach((link) => {
            link.setAttribute('href', creditsUrl);
        });
    }
}

function absorbResponse(payload) {
    if (payload.session_token) {
        state.token = payload.session_token;
        window.sessionStorage.setItem(state.tokenKey, state.token);
    }

    updatePresence(payload.presence);
    updateCredits(payload.credits, payload.credits_url);

    if (payload.status && payload.status !== state.root.dataset.status) {
        state.root.dataset.status = payload.status;
    }

    if (payload.status && payload.status !== 'active') {
        // O servidor é a fonte da verdade: encerrada a consulta, sai da tela
        // dedicada e deixa o histórico/recibo mostrar o desfecho.
        state.leaving = true;
        window.clearInterval(state.heartbeatTimer);
        window.clearTimeout(state.warningTimer);
        window.location.assign(state.root.dataset.showUrl);
    }
}

async function postPresence(kind, { activity = false, keepalive = false, retry = true } = {}) {
    if (!isActive() || (state.leaving && kind !== 'leave')) {
        return null;
    }

    const url = kind === 'leave'
        ? state.root.dataset.leaveUrl
        : state.root.dataset.presenceUrl;

    if (!url) {
        return null;
    }

    const body = {};

    if (state.token) {
        body.session_token = state.token;
    }

    if (kind === 'heartbeat') {
        body.activity = activity;
    }

    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });

        if (response.status === 403 && retry && state.token) {
            // Token de outra sessão/atendimento: descarta e abre uma nova.
            state.token = null;
            window.sessionStorage.removeItem(state.tokenKey);

            return postPresence(kind, { activity, keepalive, retry: false });
        }

        if (response.status === 422) {
            const payload = await response.json().catch(() => ({}));

            if (payload?.errors?.consultation) {
                state.leaving = true;
                window.location.assign(state.root.dataset.showUrl);
            }

            return null;
        }

        if (!response.ok) {
            return null;
        }

        const payload = await response.json();

        if (kind === 'heartbeat') {
            absorbResponse(payload);
        }

        return payload;
    } catch {
        // Rede instável: o próximo batimento reconcilia a presença e o estado.
        return null;
    }
}

async function heartbeat(activity = false) {
    if (state.sending || !isActive() || state.leaving || document.visibilityState === 'hidden') {
        return;
    }

    state.sending = true;

    if (activity) {
        state.lastActivitySentAt = Date.now();
    }

    try {
        await postPresence('heartbeat', { activity });
    } finally {
        state.sending = false;
    }
}

function scheduleInactivityWarning() {
    window.clearTimeout(state.warningTimer);

    /*
     * O aviso de inatividade é só do atendente. O cliente recebe apenas o
     * alerta de saldo baixo (`consultation-low-balance-warning`), disparado por
     * `consultation-workspace.js`: cobrar atenção dos dois lados ao mesmo tempo
     * travava a conversa sem que o cliente pudesse resolver nada. Sem o modal no
     * DOM, nenhum temporizador é criado — o batimento de presença continua
     * mantendo a sessão viva.
     */
    if (!state.inactivityModal || !isActive() || state.leaving || document.visibilityState === 'hidden') {
        return;
    }

    state.warningTimer = window.setTimeout(() => {
        if (!isActive() || state.leaving || document.visibilityState === 'hidden') {
            return;
        }

        state.inactivityModal?.show();
    }, warningDelayMs());
}

/**
 * Interação real: renova o aviso local e, no máximo a cada 5s, manda
 * `activity=true`. O intervalo evita uma requisição por tecla sem deixar o
 * servidor achar que a pessoa abandonou o atendimento.
 */
function markActivity() {
    state.lastActivityAt = Date.now();
    scheduleInactivityWarning();

    if (state.lastActivityAt - state.lastActivitySentAt >= ACTIVITY_FLUSH_MS) {
        heartbeat(true);
    }
}

function isSamePage(url) {
    return url.origin === window.location.origin && url.pathname === window.location.pathname;
}

/**
 * `true` quando o clique descarrega esta página na mesma aba.
 *
 * Os links isentos do modal de saída — `data-allow-leave`, histórico/recibo da
 * própria consulta — continuam navegando na hora, mas precisam declarar a
 * intenção para o guard não abrir o diálogo nativo no meio de uma ação
 * planejada. Âncoras de mesma página não descarregam nada, então ficam de fora.
 */
function navigatesInSameTab(anchor) {
    if (anchor.hasAttribute('download')
        || anchor.target === '_blank'
        || (anchor.dataset.bsToggle && anchor.dataset.bsToggle !== 'tab')) {
        return false;
    }

    const url = new URL(anchor.href);

    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin) {
        return false;
    }

    return !isSamePage(url);
}

function shouldWarnBeforeNavigation(anchor) {
    if (!anchor || !isActive() || state.leaving || !anchor.href) {
        return false;
    }

    if (anchor.hasAttribute('data-allow-leave')
        || anchor.hasAttribute('download')
        || anchor.target === '_blank'
        || (anchor.dataset.bsToggle && anchor.dataset.bsToggle !== 'tab')) {
        return false;
    }

    const url = new URL(anchor.href);

    if (!['http:', 'https:'].includes(url.protocol)) {
        return false;
    }

    if (url.origin !== window.location.origin || isSamePage(url)) {
        return false;
    }

    if (state.root.dataset.showUrl && url.pathname === new URL(state.root.dataset.showUrl).pathname) {
        return false;
    }

    return true;
}

function bindInteractions() {
    // Eventos de ponteiro/teclado capturam digitação, cartas e cliques em botões.
    ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, markActivity, { passive: true });
    });

    document.addEventListener('submit', (event) => {
        if (!isActive()) {
            return;
        }

        /*
         * Formulários que navegam de propósito (encerrar consulta, sair da
         * conta) trazem `data-allow-leave`: a submissão recarrega a página e,
         * sem a intenção declarada, o `beforeunload` abriria o diálogo nativo
         * no meio de uma ação que a pessoa acabou de pedir.
         */
        if (event.target instanceof HTMLFormElement && event.target.hasAttribute('data-allow-leave')) {
            markIntentionalNavigation();
        }

        heartbeat(true);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            window.clearInterval(state.heartbeatTimer);
            window.clearTimeout(state.warningTimer);
            hideInactivityWarning();

            return;
        }

        markActivity();
        heartbeat(true);
        startHeartbeat();
        scheduleInactivityWarning();
    });

    window.addEventListener('online', () => {
        markActivity();
        heartbeat(true);
    });

    window.addEventListener('pagehide', () => {
        if (isActive() && !state.leaving) {
            state.leaving = true;
            postPresence('leave', { keepalive: true });
        }

        // A página saiu: a próxima não pode herdar a intenção desta aba.
        clearIntentionalNavigation();
    });

    /*
     * Guarda de navegação para o que não passa por um clique: botão voltar do
     * navegador, atalho de teclado, URL digitada e recarregamento da página. Os
     * links continuam interceptados pelo modal "Sair do atendimento?".
     *
     * `leaveWithoutEnding` marca `leaving` antes de navegar, então a saída
     * confirmada nos modais e o redirecionamento para o histórico não abrem o
     * aviso do navegador. O mesmo vale para as ações que chamam
     * `markIntentionalNavigation()` (encerrar consulta, sair da conta, compra de
     * créditos e redirecionamentos automáticos): o diálogo nativo fica restrito
     * às saídas que a página não consegue interceptar. O texto exibido é o
     * nativo do navegador — a página não pode personalizá-lo — e a decisão sobre
     * a cobrança continua no servidor.
     */
    window.addEventListener('beforeunload', (event) => {
        if (!isActive() || state.leaving || hasIntentionalNavigation()) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }

        /*
         * Voltou do bfcache: a intenção da navegação anterior já foi consumida
         * e o aviso precisa ser rearmado junto com a presença.
         */
        clearIntentionalNavigation();

        if (state.leaving) {
            state.leaving = false;
            state.token = window.sessionStorage.getItem(state.tokenKey);
            markActivity();
            heartbeat(true);
            startHeartbeat();
        }
    });

    document.addEventListener('click', (event) => {
        const anchor = event.target.closest('a[href]');

        if (!anchor || !isActive() || state.leaving) {
            return;
        }

        if (shouldWarnBeforeNavigation(anchor)) {
            event.preventDefault();
            state.pendingNavigation = anchor.href;
            state.navigationModal?.show();

            return;
        }

        /*
         * Links isentos do modal — `data-allow-leave` (compra de créditos) e a
         * tela de histórico/recibo da consulta — seguem direto, mas declaram a
         * intenção para o `beforeunload` não abrir o alerta nativo.
         */
        if (navigatesInSameTab(anchor)) {
            markIntentionalNavigation();
        }
    });
}

function bindModals() {
    state.inactivityModal = bootstrapModal(document.querySelector('[data-consultation-inactivity-warning]'));
    state.navigationModal = bootstrapModal(document.querySelector('[data-consultation-navigation-warning]'));

    document.querySelector('[data-consultation-continue]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        hideInactivityWarning();
        markActivity();
        heartbeat(true);
    });

    document.querySelector('[data-consultation-leave-confirm]')?.addEventListener('click', () => {
        endAndLeave();
    });

    document.querySelector('[data-consultation-navigation-confirm]')?.addEventListener('click', () => {
        leaveWithoutEnding(state.pendingNavigation);
    });
}

function startHeartbeat() {
    window.clearInterval(state.heartbeatTimer);

    if (!isActive()) {
        return;
    }

    state.heartbeatTimer = window.setInterval(() => heartbeat(false), heartbeatIntervalMs());
}

function initializePresence() {
    state.root = workspaceRoot();

    if (!state.root) {
        return;
    }

    state.consultationId = state.root.dataset.consultationId;
    state.tokenKey = `arcana.consultation.presence.${state.consultationId}.${state.root.dataset.currentUserId}`;
    state.token = window.sessionStorage.getItem(state.tokenKey);
    state.lastActivitySentAt = Date.now();

    bindModals();
    bindInteractions();
    startHeartbeat();
    scheduleInactivityWarning();

    // Abrir a tela é atividade: além da presença, reinicia o relógio do servidor.
    heartbeat(true);
}

document.addEventListener('DOMContentLoaded', initializePresence);

function leaveWithoutEnding(nextUrl = null) {
    if (state.leaving) {
        return;
    }

    if (nextUrl) {
        state.pendingNavigation = nextUrl;
    }

    state.leaving = true;
    window.clearInterval(state.heartbeatTimer);
    window.clearTimeout(state.warningTimer);
    hideInactivityWarning();
    state.navigationModal?.hide();

    // `keepalive` permite a requisição sobreviver à navegação imediata.
    postPresence('leave', { keepalive: true });

    if (state.pendingNavigation) {
        window.location.assign(state.pendingNavigation);
    }
}

/*
 * "Sair do chat" encerra o atendimento.
 *
 * Quem confirma a saída no aviso de inatividade não quer mais o atendimento, e
 * a tela não pode deixar a consulta aberta consumindo créditos em segundo
 * plano. O `data-end-url` aponta para `consultations.end`: o servidor encerra a
 * consulta, cobra o último minuto iniciado, libera as duas partes e avisa o
 * outro lado. A navegação acontece depois da resposta para o encerramento não se
 * perder no meio do caminho.
 *
 * Sem permissão de encerramento (visão de leitura) resta a saída simples, e se
 * a requisição falhar o prazo de inatividade continua valendo como rede de
 * segurança — o servidor encerra a consulta sozinho.
 */
async function endAndLeave() {
    if (state.leaving) {
        return;
    }

    const endUrl = state.root?.dataset.endUrl;
    const showUrl = state.root?.dataset.showUrl;

    state.leaving = true;
    window.clearInterval(state.heartbeatTimer);
    window.clearTimeout(state.warningTimer);
    hideInactivityWarning();
    state.navigationModal?.hide();

    if (endUrl) {
        try {
            await fetch(endUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    Accept: 'text/html',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ _method: 'PATCH' }).toString(),
            });
        } catch {
            // Sem rede: a saída registrada abaixo mantém o servidor informado e
            // a inatividade encerra a consulta no prazo configurado.
            postPresence('leave', { keepalive: true });
        }
    } else {
        postPresence('leave', { keepalive: true });
    }

    if (showUrl) {
        window.location.assign(showUrl);
    }
}
