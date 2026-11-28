/**
 * Widget de suporte do visitante.
 *
 * A conversa é sempre identificada pelo cookie HttpOnly; este arquivo nunca
 * envia UUID nem token. O fluxo tem duas camadas:
 *  - HTTP: primeira carga, envio de mensagens e leitura incremental
 *    (`after_id`) a cada poucos segundos. É a via principal no Hostoo, onde o
 *    Reverb não existe;
 *  - Reverb/Echo: quando disponível, entrega a mensagem na hora. O polling
 *    continua em intervalo maior como rede de segurança para reconexões.
 *
 * Nenhuma mensagem é criada no navegador: o texto só aparece depois que o
 * servidor confirma a gravação. Assim o histórico recarregado nunca diverge do
 * que está na tela.
 */

import { createPoller, readMilliseconds } from './polling';

const READ_ERROR = 'Não foi possível carregar a conversa. Tente novamente em instantes.';
const SEND_ERROR = 'Não foi possível enviar sua mensagem. Tente novamente.';
const START_ERROR = 'Não foi possível iniciar a conversa. Revise os campos e tente novamente.';
const CLOSED_ERROR = 'Atendimento encerrado. Inicie uma nova conversa para continuar.';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function requestJson(url, { method = 'GET', body = null } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() }),
        },
        ...(body === null ? {} : { body: JSON.stringify(body) }),
    });

    let payload = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok) {
        const error = new Error(firstError(payload) ?? READ_ERROR);
        error.status = response.status;
        error.payload = payload;

        throw error;
    }

    return payload;
}

function firstError(payload) {
    const errors = payload?.errors;

    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];

        if (typeof first === 'string' && first.length > 0) {
            return first;
        }
    }

    return typeof payload?.message === 'string' && payload.message.length > 0 ? payload.message : null;
}

function initWidget(root) {
    const launcher = root.querySelector('[data-support-launcher]');
    const panel = root.querySelector('[data-support-panel]');
    const closeButton = root.querySelector('[data-support-close]');
    const badge = root.querySelector('[data-support-badge]');
    const statusDot = root.querySelector('[data-support-status-dot]');
    const statusLabel = root.querySelector('[data-support-status-label]');
    const loading = root.querySelector('[data-support-loading]');

    const startForm = root.querySelector('[data-support-start-form]');
    const startError = root.querySelector('[data-support-start-error]');
    const startSubmit = root.querySelector('[data-support-start-submit]');
    const nameInput = root.querySelector('#support-visitor-name');
    const emailInput = root.querySelector('#support-visitor-email');
    const startBody = root.querySelector('#support-visitor-message');

    const conversationWrap = root.querySelector('[data-support-conversation]');
    const messagesEl = root.querySelector('[data-support-messages]');
    const closedNote = root.querySelector('[data-support-closed]');
    const restartButton = root.querySelector('[data-support-restart]');
    const form = root.querySelector('[data-support-form]');
    const input = root.querySelector('[data-support-input]');
    const sendButton = root.querySelector('[data-support-send]');
    const sendError = root.querySelector('[data-support-send-error]');

    if (!launcher || !panel || !startForm || !form) {
        return;
    }

    const mode = root.dataset.realtimeMode === 'poll' ? 'poll' : 'socket';
    const showUrl = root.dataset.showUrl;
    const startUrl = root.dataset.startUrl;
    const messageUrl = root.dataset.messageUrl;
    const readUrl = root.dataset.readUrl;
    const broadcastAuthUrl = root.dataset.broadcastAuthUrl;
    const pollIntervalMs = readMilliseconds(
        root.dataset.pollIntervalMs,
        mode === 'poll' ? 4000 : 15000,
    );
    const backupIntervalMs = readMilliseconds(root.dataset.pollBackupIntervalMs, 15000);

    const state = {
        conversation: null,
        seen: new Set(),
        lastMessageId: 0,
        loaded: false,
        open: false,
        sending: false,
        starting: false,
        channel: null,
        subscribedUuid: null,
        poller: null,
        readTimer: null,
    };

    function setError(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message ?? '';
        element.hidden = !message;
    }

    function setStatus(availability) {
        if (!availability) {
            return;
        }

        statusLabel.textContent = availability.label ?? '';
        statusDot.classList.toggle('is-online', Boolean(availability.online));
        statusDot.classList.toggle('is-offline', !availability.online);
    }

    function setBadge(count) {
        const total = Number(count) || 0;

        badge.textContent = total > 9 ? '9+' : String(total);
        badge.hidden = total <= 0 || state.open;
    }

    function isNearBottom() {
        return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 90;
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function buildMessage(message) {
        const item = document.createElement('li');
        item.className = 'support-widget__message';
        item.dataset.messageId = String(message.id);

        if (message.mine) {
            item.classList.add('support-widget__message--mine');
        }

        const meta = document.createElement('p');
        meta.className = 'support-widget__message-meta';

        const author = document.createElement('span');
        author.textContent = message.sender_name ?? '';

        const time = document.createElement('time');
        time.dateTime = message.created_at ?? '';
        time.textContent = message.created_at_label ?? '';

        meta.append(author, time);

        const body = document.createElement('p');
        body.className = 'support-widget__message-body';
        body.textContent = message.body ?? '';

        item.append(meta, body);

        return item;
    }

    function conversationClosed() {
        return state.conversation !== null && state.conversation.is_open === false;
    }

    function applyConversationState() {
        const closed = conversationClosed();

        closedNote.hidden = !closed;
        restartButton.hidden = !closed;
        form.hidden = closed;

        if (state.conversation) {
            const unread = state.open ? 0 : Number(state.conversation.unread_count) || 0;
            setBadge(unread);
        }
    }

    function appendMessages(messages, { reset = false } = {}) {
        if (reset) {
            messagesEl.replaceChildren();
            state.seen.clear();
            state.lastMessageId = 0;
        }

        const nearBottom = isNearBottom();
        let appended = 0;

        (messages ?? []).forEach((message) => {
            const id = Number(message?.id);

            if (!Number.isFinite(id) || state.seen.has(id)) {
                return;
            }

            state.seen.add(id);
            state.lastMessageId = Math.max(state.lastMessageId, id);
            messagesEl.append(buildMessage(message));
            appended += 1;
        });

        if (appended > 0 && (nearBottom || reset)) {
            scrollToBottom();
        }

        return appended;
    }

    function showConversation() {
        loading.hidden = true;
        startForm.hidden = true;
        conversationWrap.hidden = false;

        applyConversationState();
    }

    function showStartForm() {
        loading.hidden = true;
        conversationWrap.hidden = true;
        startForm.hidden = false;
    }

    function applyResponse(payload, { reset = false } = {}) {
        state.loaded = true;
        state.conversation = payload?.conversation ?? null;

        setStatus(payload?.availability);

        if (!state.conversation) {
            stopPolling();
            leaveChannel();
            showStartForm();

            return;
        }

        appendMessages(payload.messages ?? [], { reset });
        showConversation();

        if (state.conversation.is_open) {
            startPolling();
            subscribe(state.conversation.uuid);
        } else {
            stopPolling();
            leaveChannel();
        }

        if (state.open && Number(state.conversation.unread_count) > 0) {
            scheduleMarkRead();
        }
    }

    async function refresh() {
        if (!state.conversation) {
            return;
        }

        const payload = await requestJson(`${showUrl}?after_id=${state.lastMessageId}`);

        applyResponse(payload);
    }

    function startPolling() {
        if (state.poller === null) {
            state.poller = createPoller({
                intervalMs: mode === 'poll' ? pollIntervalMs : backupIntervalMs,
                hiddenIntervalMs: Math.max(backupIntervalMs, 15000),
                run: refresh,
                immediate: false,
            });
        }

        state.poller.start();
    }

    function stopPolling() {
        state.poller?.stop();
    }

    function leaveChannel() {
        if (state.channel !== null) {
            state.channel.stopListening('.support.message');
            state.channel.stopListening('.support.conversation');
            window.Echo?.leave(`support.conversation.${state.subscribedUuid}`);
        }

        state.channel = null;
        state.subscribedUuid = null;
    }

    function subscribe(uuid) {
        if (!uuid || state.subscribedUuid === uuid) {
            return;
        }

        leaveChannel();

        const echo = window.initializeEcho?.({ authEndpoint: broadcastAuthUrl });

        if (!echo) {
            return;
        }

        state.subscribedUuid = uuid;
        state.channel = echo.private(`support.conversation.${uuid}`);

        state.channel.listen('.support.message', (payload) => {
            if (payload?.conversation?.uuid !== state.subscribedUuid) {
                return;
            }

            const message = payload.message;

            if (!message) {
                return;
            }

            appendMessages([message]);

            state.conversation = { ...state.conversation, ...payload.conversation };

            if (message.mine === false) {
                const unread = state.open ? 0 : (Number(state.conversation.unread_count) || 0) + 1;
                state.conversation.unread_count = unread;
            }

            applyConversationState();

            if (state.open && message.mine === false) {
                scheduleMarkRead();
            } else {
                setBadge(state.conversation.unread_count);
            }

            if (state.conversation.is_open === false) {
                stopPolling();
                leaveChannel();
            }
        });

        state.channel.listen('.support.conversation', (payload) => {
            if (!state.conversation || payload?.conversation?.uuid !== state.conversation.uuid) {
                return;
            }

            state.conversation = { ...state.conversation, ...payload.conversation };
            applyConversationState();

            if (state.conversation.is_open === false) {
                stopPolling();
                leaveChannel();
            }
        });
    }

    async function markRead() {
        window.clearTimeout(state.readTimer);
        state.readTimer = null;

        if (!state.conversation || document.visibilityState !== 'visible') {
            return;
        }

        try {
            await requestJson(readUrl, { method: 'POST' });

            if (state.conversation) {
                state.conversation.unread_count = 0;
            }

            setBadge(0);
        } catch {
            // A leitura é otimista: uma falha aqui não interrompe a conversa e
            // o próximo ciclo tenta de novo.
        }
    }

    function scheduleMarkRead() {
        if (!state.open || state.readTimer !== null) {
            return;
        }

        state.readTimer = window.setTimeout(markRead, 600);
    }

    async function load() {
        loading.textContent = 'Carregando atendimento…';
        loading.hidden = false;
        setError(startError, null);
        setError(sendError, null);

        try {
            const payload = await requestJson(showUrl);
            applyResponse(payload, { reset: true });
        } catch (error) {
            state.loaded = false;
            loading.textContent = error?.message ?? READ_ERROR;
        }
    }

    function openPanel() {
        if (state.open) {
            return;
        }

        state.open = true;
        panel.hidden = false;
        root.classList.add('is-open');
        launcher.setAttribute('aria-expanded', 'true');
        setBadge(0);

        if (!state.loaded) {
            void load();
        } else if (conversationClosed()) {
            restartButton.focus();
        } else if (state.conversation !== null) {
            input.focus();
        } else if (nameInput.value.trim() === '') {
            nameInput.focus();
        } else {
            startBody.focus();
        }

        scheduleMarkRead();
    }

    function closePanel() {
        if (!state.open) {
            return;
        }

        state.open = false;
        panel.hidden = true;
        root.classList.remove('is-open');
        launcher.setAttribute('aria-expanded', 'false');
        setBadge(state.conversation?.unread_count ?? 0);
        launcher.focus();
    }

    async function handleStart(event) {
        event.preventDefault();

        if (state.starting) {
            return;
        }

        const visitorName = nameInput.value.trim();
        const visitorEmail = emailInput.value.trim();
        const body = startBody.value.trim();

        if (visitorName === '' || body === '') {
            setError(startError, 'Informe seu nome e escreva uma mensagem.');

            return;
        }

        state.starting = true;
        startSubmit.disabled = true;
        setError(startError, null);

        try {
            const payload = await requestJson(startUrl, {
                method: 'POST',
                body: {
                    visitor_name: visitorName,
                    visitor_email: visitorEmail === '' ? null : visitorEmail,
                    body,
                },
            });

            startBody.value = '';
            applyResponse(payload, { reset: true });

            if (state.conversation?.is_open) {
                input.focus();
            }
        } catch (error) {
            setError(startError, error?.message ?? START_ERROR);
        } finally {
            state.starting = false;
            startSubmit.disabled = false;
        }
    }

    async function handleSend(event) {
        event.preventDefault();

        if (state.sending || !state.conversation || conversationClosed()) {
            return;
        }

        const body = input.value.trim();

        if (body === '') {
            return;
        }

        state.sending = true;
        sendButton.disabled = true;
        setError(sendError, null);

        try {
            const payload = await requestJson(messageUrl, {
                method: 'POST',
                body: { body },
            });

            input.value = '';
            appendMessages([payload.message]);

            if (state.conversation) {
                state.conversation.last_message_at = payload.message?.created_at ?? state.conversation.last_message_at;
            }

            scrollToBottom();
        } catch (error) {
            const message = error?.message ?? SEND_ERROR;

            setError(sendError, /encerrada/i.test(message) ? CLOSED_ERROR : message);

            if (error?.status === 422 && /encerrada/i.test(message)) {
                void load();
            }
        } finally {
            state.sending = false;
            sendButton.disabled = false;
            input.focus();
        }
    }

    function handleRestart() {
        const previous = state.conversation;

        if (previous?.visitor_name) {
            nameInput.value = previous.visitor_name;
        }

        state.conversation = null;
        state.seen.clear();
        state.lastMessageId = 0;
        messagesEl.replaceChildren();

        stopPolling();
        leaveChannel();
        showStartForm();
        setBadge(0);
        setError(startError, null);
        setError(sendError, null);
        startBody.value = '';
        startBody.focus();
    }

    launcher.addEventListener('click', () => {
        if (state.open) {
            closePanel();
        } else {
            openPanel();
        }
    });

    closeButton?.addEventListener('click', closePanel);
    restartButton?.addEventListener('click', handleRestart);
    startForm.addEventListener('submit', handleStart);
    form.addEventListener('submit', handleSend);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.open) {
            closePanel();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && state.open) {
            scheduleMarkRead();
        }
    });

    void load();
}

function init() {
    document.querySelectorAll('[data-support-widget]').forEach(initWidget);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
