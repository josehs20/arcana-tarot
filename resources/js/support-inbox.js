/**
 * Central de suporte administrativa.
 *
 * Um único widget aberto em qualquer tela do painel administrativo mantém:
 *  - a lista de conversas abertas e encerradas;
 *  - a thread da conversa selecionada, com envio de resposta e encerramento;
 *  - a presença do operador (heartbeat HTTP), que é o que o visitante vê como
 *    "equipe online";
 *  - o total de mensagens não lidas no botão flutuante.
 *
 * O Reverb entrega `support.inbox` na hora. Sem Reverb (Hostoo), a lista e a
 * thread são relidas por HTTP — a mesma leitura que também serve de rede de
 * segurança quando o WebSocket cai. Toda mensagem enviada só aparece depois de
 * gravada no servidor.
 */

import { createPoller, readMilliseconds } from './polling';

const LIST_ERROR = 'Não foi possível carregar as conversas.';
const THREAD_ERROR = 'Não foi possível carregar esta conversa.';
const SEND_ERROR = 'Não foi possível enviar a resposta.';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
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
        const error = new Error(firstError(payload) ?? LIST_ERROR);
        error.status = response.status;
        error.payload = payload;

        throw error;
    }

    return payload;
}

function initInbox(root) {
    const launcher = root.querySelector('[data-support-inbox-launcher]');
    const panel = root.querySelector('[data-support-inbox-panel]');
    const closeButton = root.querySelector('[data-support-inbox-close]');
    const badge = root.querySelector('[data-support-inbox-badge]');
    const summaryEl = root.querySelector('[data-support-inbox-summary]');

    const listEl = root.querySelector('[data-support-inbox-list]');
    const emptyEl = root.querySelector('[data-support-inbox-empty]');
    const listError = root.querySelector('[data-support-inbox-list-error]');

    const thread = root.querySelector('[data-support-inbox-thread]');
    const visitorNameEl = root.querySelector('[data-support-visitor-name]');
    const visitorMetaEl = root.querySelector('[data-support-visitor-meta]');
    const statusPill = root.querySelector('[data-support-conversation-status]');
    const closeConversationButton = root.querySelector('[data-support-close-conversation]');
    const backButton = root.querySelector('[data-support-inbox-back]');
    const messagesEl = root.querySelector('[data-support-inbox-messages]');
    const closedNote = root.querySelector('[data-support-closed-note]');
    const form = root.querySelector('[data-support-inbox-form]');
    const input = root.querySelector('[data-support-inbox-input]');
    const sendButton = root.querySelector('[data-support-inbox-send]');
    const threadError = root.querySelector('[data-support-inbox-error]');
    const filterButtons = Array.from(root.querySelectorAll('[data-support-inbox-filter]'));

    if (!launcher || !panel || !listEl || !thread) {
        return;
    }

    const mode = root.dataset.realtimeMode === 'poll' ? 'poll' : 'socket';
    const conversationsUrl = root.dataset.conversationsUrl;
    const conversationUrl = root.dataset.conversationUrl;
    const messageUrl = root.dataset.messageUrl;
    const readUrl = root.dataset.readUrl;
    const closeUrl = root.dataset.closeUrl;
    const presenceUrl = root.dataset.presenceUrl;

    const pollListMs = readMilliseconds(root.dataset.pollListMs, mode === 'poll' ? 4000 : 15000);
    const pollListBackupMs = readMilliseconds(root.dataset.pollListBackupMs, 15000);
    const pollMessagesMs = readMilliseconds(root.dataset.pollMessagesMs, mode === 'poll' ? 2500 : 15000);
    const pollMessagesBackupMs = readMilliseconds(root.dataset.pollMessagesBackupMs, 15000);
    const presenceIntervalMs = readMilliseconds(root.dataset.presenceIntervalMs, 45000);

    const state = {
        open: false,
        loaded: false,
        filter: 'open',
        conversations: [],
        active: null,
        seen: new Set(),
        lastMessageId: 0,
        sending: false,
        closing: false,
        reading: false,
        lastReadAt: 0,
        lastReadConversationId: null,
        listPoller: null,
        threadPoller: null,
        listRefreshTimer: null,
        channel: null,
    };

    function urlFor(template, id) {
        return String(template).replace('__ID__', String(id));
    }

    function setError(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message ?? '';
        element.hidden = !message;
    }

    function setBadge(total) {
        const count = Number(total) || 0;

        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count <= 0;
        root.classList.toggle('has-unread', count > 0);
    }

    function renderSummary(summary) {
        if (!summary) {
            return;
        }

        summaryEl.textContent = `${summary.open} ${summary.open === 1 ? 'conversa aberta' : 'conversas abertas'} · ${summary.unread_total} ${summary.unread_total === 1 ? 'não lida' : 'não lidas'}`;
        setBadge(summary.unread_total);
    }

    function buildTab(conversation) {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'support-inbox__tab';
        tab.dataset.conversationId = String(conversation.id);
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', state.active?.id === conversation.id ? 'true' : 'false');

        if (state.active?.id === conversation.id) {
            tab.classList.add('is-active');
        }

        const head = document.createElement('span');
        head.className = 'support-inbox__tab-head';

        const name = document.createElement('span');
        name.className = 'support-inbox__tab-name';
        name.textContent = conversation.visitor_name ?? 'Visitante';

        const time = document.createElement('span');
        time.className = 'support-inbox__tab-time';
        time.textContent = conversation.last_message_at_label ?? '';

        head.append(name, time);

        const preview = document.createElement('span');
        preview.className = 'support-inbox__tab-preview';
        preview.textContent = conversation.preview ?? 'Sem mensagens.';

        const foot = document.createElement('span');
        foot.className = 'support-inbox__tab-foot';

        const status = document.createElement('span');
        status.className = 'support-inbox__tab-status';
        status.textContent = conversation.status_label ?? '';

        if (!conversation.is_open) {
            status.classList.add('is-closed');
        }

        foot.append(status);

        const unread = Number(conversation.unread_count) || 0;

        if (unread > 0) {
            const pill = document.createElement('span');
            pill.className = 'support-inbox__tab-unread';
            pill.textContent = unread > 99 ? '99+' : String(unread);
            foot.append(pill);
        }

        tab.append(head, preview, foot);

        tab.addEventListener('click', () => {
            void selectConversation(conversation.id);
        });

        return tab;
    }

    function renderList() {
        const focusedId = document.activeElement?.dataset?.conversationId;
        listEl.querySelectorAll('[data-conversation-id]').forEach((node) => node.remove());

        const empty = state.conversations.length === 0;

        emptyEl.hidden = !empty;
        emptyEl.textContent = state.filter === 'closed'
            ? 'Nenhuma conversa encerrada nos últimos dias.'
            : 'Nenhuma conversa aberta agora.';

        state.conversations.forEach((conversation) => {
            listEl.append(buildTab(conversation));
        });

        if (focusedId) {
            listEl.querySelector(`[data-conversation-id="${focusedId}"]`)?.focus();
        }
    }

    function renderThreadHeader() {
        const conversation = state.active;

        if (!conversation) {
            return;
        }

        visitorNameEl.textContent = conversation.visitor_name ?? 'Visitante';

        const meta = [];

        if (conversation.visitor_email) {
            meta.push(conversation.visitor_email);
        }

        if (conversation.assigned_user_name) {
            meta.push(`Atendente: ${conversation.assigned_user_name}`);
        }

        if (conversation.last_message_at_label) {
            meta.push(`Última mensagem: ${conversation.last_message_at_label}`);
        }

        visitorMetaEl.textContent = meta.join(' · ');
        visitorMetaEl.hidden = meta.length === 0;

        statusPill.textContent = conversation.status_label ?? '';
        statusPill.classList.toggle('is-closed', !conversation.is_open);

        closeConversationButton.hidden = !conversation.is_open;
        closedNote.hidden = conversation.is_open;
        form.hidden = !conversation.is_open;
    }

    function buildMessage(message) {
        const item = document.createElement('li');
        item.className = 'support-inbox__message';
        item.dataset.messageId = String(message.id);

        if (message.sender_type === 'operator') {
            item.classList.add('support-inbox__message--operator');
        }

        const meta = document.createElement('p');
        meta.className = 'support-inbox__message-meta';

        const author = document.createElement('span');
        author.textContent = message.sender_name ?? '';

        const time = document.createElement('time');
        time.dateTime = message.created_at ?? '';
        time.textContent = message.created_at_label ?? '';

        meta.append(author, time);

        const body = document.createElement('p');
        body.className = 'support-inbox__message-body';
        body.textContent = message.body ?? '';

        item.append(meta, body);

        return item;
    }

    function appendMessages(messages, { reset = false } = {}) {
        if (reset) {
            messagesEl.replaceChildren();
            state.seen.clear();
            state.lastMessageId = 0;
        }

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

        if (appended > 0) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        return appended;
    }

    function syncListEntry(conversation) {
        const index = state.conversations.findIndex((item) => item.id === conversation.id);

        if (index === -1) {
            return;
        }

        state.conversations[index] = {
            ...state.conversations[index],
            ...conversation,
            unread_count: conversation.unread_count ?? state.conversations[index].unread_count,
        };
    }

    function syncActiveFromList(conversations) {
        if (!state.active) {
            return;
        }

        const fresh = conversations.find((item) => item.id === state.active.id);

        if (!fresh) {
            return;
        }

        state.active = { ...state.active, ...fresh };
        renderThreadHeader();

        if (!state.active.is_open) {
            stopThreadPolling();
        }
    }

    async function fetchList({ silent = false } = {}) {
        try {
            const payload = await requestJson(`${conversationsUrl}?status=${state.filter}`);

            state.conversations = payload.conversations ?? [];
            state.loaded = true;

            renderSummary(payload.summary);
            syncActiveFromList(state.conversations);
            renderList();
            setError(listError, null);

            if (state.open) {
                maybeAutoSelect();
            }

            return payload;
        } catch (error) {
            if (!silent) {
                setError(listError, error?.message ?? LIST_ERROR);
            }

            throw error;
        }
    }

    function maybeAutoSelect() {
        if (!state.open || state.active !== null || state.conversations.length === 0) {
            return;
        }

        const preferred = state.conversations.find((conversation) => Number(conversation.unread_count) > 0)
            ?? state.conversations[0];

        void selectConversation(preferred.id);
    }

    function startListPolling() {
        if (state.listPoller === null) {
            state.listPoller = createPoller({
                intervalMs: mode === 'poll' ? pollListMs : pollListBackupMs,
                hiddenIntervalMs: Math.max(pollListBackupMs, 15000),
                run: () => fetchList({ silent: true }),
                immediate: false,
            });
        }

        state.listPoller.start();
    }

    function startThreadPolling() {
        if (state.threadPoller === null) {
            state.threadPoller = createPoller({
                intervalMs: mode === 'poll' ? pollMessagesMs : pollMessagesBackupMs,
                hiddenIntervalMs: Math.max(pollMessagesBackupMs, 15000),
                run: () => refreshThread(),
                immediate: false,
            });
        }

        state.threadPoller.start();
    }

    function stopThreadPolling() {
        state.threadPoller?.stop();
    }

    async function refreshThread({ reset = false } = {}) {
        const conversation = state.active;

        if (!conversation) {
            return;
        }

        const id = conversation.id;

        try {
            const payload = await requestJson(
                `${urlFor(conversationUrl, id)}?after_id=${reset ? 0 : state.lastMessageId}`,
            );

            if (state.active?.id !== id) {
                return;
            }

            applyThread(payload, { reset });
        } catch (error) {
            setError(threadError, error?.message ?? THREAD_ERROR);

            throw error;
        }
    }

    function applyThread(payload, { reset = false } = {}) {
        if (!payload?.conversation || !state.active) {
            return;
        }

        state.active = { ...state.active, ...payload.conversation };

        renderThreadHeader();
        appendMessages(payload.messages ?? [], { reset });
        renderSummary(payload.summary);
        syncListEntry(state.active);
        renderList();

        if (!state.active.is_open) {
            stopThreadPolling();
        }

        if (state.open && Number(state.active.unread_count) > 0) {
            void markReadActive();
        }
    }

    async function markReadActive() {
        const conversation = state.active;

        if (!conversation || state.reading) {
            return;
        }

        if (!state.open || document.visibilityState !== 'visible') {
            return;
        }

        if (Date.now() - state.lastReadAt < 1500 && state.lastReadConversationId === conversation.id) {
            return;
        }

        state.reading = true;
        state.lastReadAt = Date.now();
        state.lastReadConversationId = conversation.id;

        try {
            const payload = await requestJson(urlFor(readUrl, conversation.id), { method: 'POST' });

            if (state.active?.id === conversation.id) {
                state.active.unread_count = 0;
                syncListEntry(state.active);
                renderList();
            }

            if (Number.isFinite(Number(payload?.unread_total))) {
                setBadge(payload.unread_total);
            }
        } catch {
            // Leitura é acessório: a thread continua utilizável sem ela.
        } finally {
            state.reading = false;
        }
    }

    async function selectConversation(id) {
        const conversation = state.conversations.find((item) => item.id === id);

        if (!conversation) {
            return;
        }

        if (state.active?.id === id) {
            thread.hidden = false;
            root.classList.add('is-thread-open');

            return;
        }

        state.active = { ...conversation, messages: [] };
        state.seen.clear();
        state.lastMessageId = 0;
        messagesEl.replaceChildren();
        setError(threadError, null);

        thread.hidden = false;
        root.classList.add('is-thread-open');
        renderThreadHeader();
        renderList();
        stopThreadPolling();

        await refreshThread({ reset: true }).catch(() => {});

        if (state.active?.id === id) {
            startThreadPolling();
            void markReadActive();

            if (state.active.is_open) {
                input.focus();
            }
        }
    }

    function clearThread() {
        stopThreadPolling();
        state.active = null;
        state.seen.clear();
        state.lastMessageId = 0;
        messagesEl.replaceChildren();
        thread.hidden = true;
        root.classList.remove('is-thread-open');
        setError(threadError, null);
        renderList();
    }

    async function openPanel() {
        if (state.open) {
            return;
        }

        state.open = true;
        panel.hidden = false;
        root.classList.add('is-open');
        launcher.setAttribute('aria-expanded', 'true');

        const hadActive = state.active !== null;

        if (!state.loaded) {
            await fetchList().catch(() => {});
        } else {
            maybeAutoSelect();
        }

        if (state.active) {
            thread.hidden = false;
            root.classList.add('is-thread-open');

            if (hadActive) {
                void refreshThread().catch(() => {});
            }

            startThreadPolling();
        }

        if (state.active?.is_open) {
            input.focus();
        }
    }

    function closePanel() {
        state.open = false;
        panel.hidden = true;
        root.classList.remove('is-open');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.focus();
        stopThreadPolling();
    }

    function scheduleListRefresh() {
        window.clearTimeout(state.listRefreshTimer);
        state.listRefreshTimer = window.setTimeout(() => {
            void fetchList({ silent: true }).catch(() => {});
        }, 500);
    }

    async function sendMessage() {
        const conversation = state.active;

        if (!conversation || !conversation.is_open || state.sending) {
            return;
        }

        const body = input.value.trim();

        if (body === '') {
            return;
        }

        state.sending = true;
        sendButton.disabled = true;
        sendButton.setAttribute('aria-busy', 'true');
        setError(threadError, null);

        try {
            const payload = await requestJson(urlFor(messageUrl, conversation.id), {
                method: 'POST',
                body: { body },
            });

            if (state.active?.id !== conversation.id) {
                return;
            }

            if (payload?.message) {
                appendMessages([payload.message]);
            }

            if (payload?.conversation) {
                state.active = { ...state.active, ...payload.conversation };
            }

            renderThreadHeader();
            renderSummary(payload?.summary);
            syncListEntry(state.active);
            renderList();
            input.value = '';
            input.focus();
        } catch (error) {
            setError(threadError, error?.message ?? SEND_ERROR);
        } finally {
            state.sending = false;
            sendButton.disabled = false;
            sendButton.removeAttribute('aria-busy');
        }
    }

    async function closeConversation() {
        const conversation = state.active;

        if (!conversation || !conversation.is_open || state.closing) {
            return;
        }

        if (!window.confirm('Encerrar esta conversa? O visitante poderá iniciar outra quando quiser.')) {
            return;
        }

        state.closing = true;
        closeConversationButton.disabled = true;
        setError(threadError, null);

        try {
            const payload = await requestJson(urlFor(closeUrl, conversation.id), { method: 'POST' });

            if (state.active?.id === conversation.id && payload?.conversation) {
                state.active = { ...state.active, ...payload.conversation };
            }

            renderThreadHeader();
            renderSummary(payload?.summary);
            syncListEntry(state.active ?? conversation);
            stopThreadPolling();
            void fetchList({ silent: true }).catch(() => {});
        } catch (error) {
            setError(threadError, error?.message ?? 'Não foi possível encerrar a conversa.');
        } finally {
            state.closing = false;
            closeConversationButton.disabled = false;
        }
    }

    function handleMessageEvent(payload) {
        const conversation = payload?.conversation;
        const message = payload?.message;

        if (!conversation || !message) {
            return;
        }

        if (state.active && state.active.id === conversation.id) {
            appendMessages([message]);

            state.active = { ...state.active, ...conversation };
            renderThreadHeader();
            syncListEntry(state.active);

            if (state.open && message.sender_type === 'visitor') {
                void markReadActive();
            }
        }

        scheduleListRefresh();
    }

    function handleConversationEvent(payload) {
        const conversation = payload?.conversation;

        if (!conversation) {
            return;
        }

        if (state.active && state.active.id === conversation.id) {
            state.active = { ...state.active, ...conversation };
            renderThreadHeader();
            syncListEntry(state.active);

            if (!state.active.is_open) {
                stopThreadPolling();
            }
        }

        scheduleListRefresh();
    }

    function subscribeToRealtime() {
        /*
         * O painel usa o endpoint padrão do Laravel (`/broadcasting/auth`), que
         * autoriza `support.inbox` pela sessão do administrador. O endpoint do
         * visitante só entende cookie de conversa e negaria esta assinatura.
         */
        const echo = window.initializeEcho?.();

        if (!echo) {
            return;
        }

        state.channel = echo.private('support.inbox');
        state.channel.listen('.support.message', handleMessageEvent);
        state.channel.listen('.support.conversation', handleConversationEvent);
    }

    function sendHeartbeat() {
        if (!presenceUrl || document.visibilityState === 'hidden' || navigator.onLine === false) {
            return;
        }

        void requestJson(presenceUrl, { method: 'POST' }).catch(() => {});
    }

    launcher.addEventListener('click', () => {
        if (state.open) {
            closePanel();

            return;
        }

        void openPanel();
    });

    closeButton?.addEventListener('click', closePanel);
    backButton?.addEventListener('click', clearThread);
    closeConversationButton?.addEventListener('click', () => void closeConversation());

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        void sendMessage();
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void sendMessage();
        }
    });

    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.supportInboxFilter === 'closed' ? 'closed' : 'open';

            if (state.filter === filter) {
                return;
            }

            state.filter = filter;

            filterButtons.forEach((item) => {
                const active = item === button;

                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            clearThread();
            setError(listError, null);
            void fetchList().catch(() => {});
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.open) {
            closePanel();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        sendHeartbeat();
        void markReadActive();
    });

    window.addEventListener('online', sendHeartbeat);

    subscribeToRealtime();
    sendHeartbeat();
    window.setInterval(sendHeartbeat, presenceIntervalMs);

    void fetchList()
        .catch(() => {})
        .finally(() => {
            startListPolling();

            if (root.dataset.autoOpen === '1') {
                void openPanel();
            }
        });
}

document.querySelectorAll('[data-support-inbox]').forEach((root) => {
    initInbox(root);
});
