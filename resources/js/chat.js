import { createPoller, readMilliseconds } from './polling';

/*
 * Rede de segurança quando o WebSocket está disponível: com o canal conectado
 * quem entrega a mensagem é o broadcast, então esta leitura só cobre o que se
 * perdeu durante uma reconexão. Sem WebSocket (modo `poll`) o mesmo agendador
 * roda no intervalo curto publicado pelo servidor em `data-poll-*`.
 */
const SOCKET_SAFETY_INTERVAL_MS = 30000;
const MAX_BACKFILL_PAGES = 5;

// Estados finais: nada mais chega, então as leituras periódicas param.
const FINAL_STATUSES = new Set(['ended', 'cancelled', 'rejected', 'expired', 'interrupted']);

const chatState = {
    userId: null,
    consultationId: null,
    connection: 'connecting',
    realtimeMode: 'socket',
    syncing: false,
    refreshing: false,
    sending: false,
    readTimer: null,
    poller: null,
    sendOnEnter: true,
    soundEnabled: true,
    soundArmed: false,
    audio: null,
};

function currentChat() {
    return document.querySelector('[data-chat]');
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function setConnection(chat, connectionState) {
    if (!chat) {
        return;
    }

    chatState.connection = connectionState;

    const badge = chat.querySelector('[data-chat-connection]');

    if (!badge) {
        return;
    }

    badge.classList.remove('d-none', 'bg-secondary', 'bg-success', 'bg-warning', 'bg-danger', 'bg-info', 'text-dark');

    if (connectionState === 'connected') {
        badge.classList.add('bg-success');
        badge.textContent = 'Tempo real conectado';

        return;
    }

    if (connectionState === 'connecting') {
        badge.classList.add('bg-secondary');
        badge.textContent = 'Conectando…';

        return;
    }

    /*
     * Modo polling: não existe canal para "conectar", então o indicador fala de
     * atualização automática em vez de sugerir uma conexão que nunca virá.
     */
    if (connectionState === 'polling') {
        badge.classList.add('bg-info', 'text-dark');
        badge.textContent = 'Atualização automática';

        return;
    }

    if (connectionState === 'offline') {
        badge.classList.add('bg-danger');
        badge.textContent = 'Sem conexão';

        return;
    }

    badge.classList.add('bg-warning', 'text-dark');
    badge.textContent = 'Reconectando…';
}

function setNotice(chat, message) {
    const notice = chat?.querySelector('[data-chat-notice]');

    if (!notice) {
        return;
    }

    notice.textContent = message ?? '';
    notice.classList.toggle('d-none', !message);
}

/*
 * Preferências de conversa.
 *
 * O estado inicial vem do servidor (`data-send-on-enter` e `data-sound-enabled`,
 * renderizados a partir do perfil do usuário) e cada mudança é gravada em
 * `preferences.chat.update` sem recarregar a página. `chatState` é a fonte de
 * verdade em memória: quando `refreshState` substitui o bloco do chat, os
 * interruptores são reaplicados a partir dele para não voltarem ao valor antigo.
 */
function preferenceInput(chat, name) {
    return chat?.querySelector(`[data-chat-preference="${name}"]`) ?? null;
}

function applyChatPreferences(chat) {
    if (!chat) {
        return;
    }

    chat.dataset.sendOnEnter = chatState.sendOnEnter ? '1' : '0';
    chat.dataset.soundEnabled = chatState.soundEnabled ? '1' : '0';

    const enter = preferenceInput(chat, 'send_on_enter');
    const sound = preferenceInput(chat, 'sound_enabled');

    if (enter) {
        enter.checked = chatState.sendOnEnter;
    }

    if (sound) {
        sound.checked = chatState.soundEnabled;
    }

    const mode = chat.querySelector('[data-chat-send-mode]');

    if (mode) {
        mode.textContent = chatState.sendOnEnter
            ? 'Enter envia · Shift+Enter quebra linha.'
            : 'Enter quebra linha · Ctrl+Enter envia.';
    }
}

/*
 * A gravação é silenciosa de propósito: o interruptor já mudou na tela e a
 * próxima alteração tenta novamente. Perder a preferência não pode bloquear a
 * conversa nem exibir erro para o usuário.
 */
function persistChatPreferences(chat) {
    const url = chat?.dataset.preferencesUrl;

    if (!url) {
        return;
    }

    fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            send_on_enter: chatState.sendOnEnter,
            sound_enabled: chatState.soundEnabled,
        }),
    }).catch(() => {});
}

function bindChatPreferences(chat) {
    if (!chat) {
        return;
    }

    chat.querySelectorAll('[data-chat-preference]').forEach((input) => {
        if (input.dataset.chatPreferenceBound === '1') {
            return;
        }

        input.dataset.chatPreferenceBound = '1';

        input.addEventListener('change', () => {
            if (input.dataset.chatPreference === 'send_on_enter') {
                chatState.sendOnEnter = input.checked;
            } else {
                chatState.soundEnabled = input.checked;
            }

            applyChatPreferences(chat);
            persistChatPreferences(chat);
        });
    });
}

/*
 * Aviso sonoro de mensagem recebida.
 *
 * O tom é sintetizado no navegador — nenhum arquivo externo entra no bundle e
 * não há requisição extra. Navegadores bloqueiam áudio antes de uma interação,
 * então o contexto só sai de "suspended" no primeiro clique/tecla da página.
 *
 * Silêncios deliberados: nada toca para mensagens do próprio usuário, durante a
 * carga inicial do histórico (`soundArmed`) ou com o aviso desligado.
 */
function primeChatAudio() {
    const AudioContextClass = window.AudioContext ?? window.webkitAudioContext;

    if (!AudioContextClass) {
        return;
    }

    chatState.audio ??= new AudioContextClass();

    if (chatState.audio.state === 'suspended') {
        chatState.audio.resume().catch(() => {});
    }
}

function playIncomingSound() {
    if (!chatState.soundEnabled || !chatState.soundArmed) {
        return;
    }

    const context = chatState.audio;

    if (!context || context.state !== 'running') {
        return;
    }

    const start = context.currentTime;

    const gain = context.createGain();
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(0.09, start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.44);

    const oscillator = context.createOscillator();
    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(880, start);
    oscillator.frequency.setValueAtTime(660, start + 0.16);

    oscillator.connect(gain).connect(context.destination);
    oscillator.start(start);
    oscillator.stop(start + 0.46);
}

function notifyIncoming(message) {
    if (Number(message?.sender_id) === Number(chatState.userId)) {
        return;
    }

    playIncomingSound();
}

function lastMessageId(chat) {
    if (!chat) {
        return 0;
    }

    return Array.from(chat.querySelectorAll('[data-message-id]')).reduce((highest, element) => {
        const id = Number(element.dataset.messageId);

        return Number.isFinite(id) && id > highest ? id : highest;
    }, 0);
}

function hasMessage(chat, messageId) {
    return chat.querySelector(`[data-message-id="${Number(messageId)}"]`) !== null;
}

function createTextElement(tag, className, text) {
    const element = document.createElement(tag);

    if (className) {
        element.className = className;
    }

    element.textContent = text ?? '';

    return element;
}

function createMessageElement(message, currentUserId) {
    const mine = Number(message.sender_id) === Number(currentUserId);
    const isCard = message.type === 'tarot_card';
    const item = document.createElement('li');

    item.className = `chat-message${mine ? ' chat-message--mine' : ''}`;
    item.dataset.messageId = String(message.id);

    const meta = document.createElement('div');
    meta.className = 'chat-message__meta';

    const sender = createTextElement(
        'span',
        'chat-message__sender',
        `${mine ? 'Você' : message.sender_name} · ${message.sender_role_label}`,
    );

    const time = document.createElement('time');
    time.dateTime = message.created_at ?? '';
    time.textContent = message.created_at_label ?? '—';

    meta.append(sender, time);
    item.append(meta);

    if (isCard) {
        const figure = document.createElement('figure');
        figure.className = 'chat-card mb-0';

        if (message.image_url) {
            const link = document.createElement('a');
            link.href = message.image_url;
            link.target = '_blank';
            link.rel = 'noopener';

            const image = document.createElement('img');
            image.src = message.image_url;
            image.alt = `Carta ${message.card_name ?? ''}`.trim();
            image.loading = 'lazy';

            link.append(image);
            figure.append(link);
        }

        const caption = document.createElement('figcaption');
        caption.append(
            createTextElement('strong', '', message.card_name ?? 'Carta'),
            createTextElement('span', '', message.deck_name ?? ''),
        );

        figure.append(caption);
        item.append(figure);
    } else {
        item.append(createTextElement('p', 'chat-message__body', message.body ?? ''));
    }

    return item;
}

function insertMessage(chat, message) {
    if (!message?.id || hasMessage(chat, message.id)) {
        return false;
    }

    const timeline = chat.querySelector('[data-chat-timeline]');

    if (!timeline) {
        return false;
    }

    const nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 200;
    const element = createMessageElement(message, chatState.userId);
    const next = Array.from(timeline.querySelectorAll('[data-message-id]')).find(
        (existing) => Number(existing.dataset.messageId) > Number(message.id),
    );

    /*
     * A ordem cronológica é garantida mesmo quando o eco do broadcast chega
     * antes da resposta do envio (ou depois, em uma reconexão com backfill).
     */
    if (next) {
        next.before(element);
    } else {
        timeline.append(element);
    }

    timeline.classList.remove('d-none');
    chat.querySelector('[data-chat-empty]')?.classList.add('d-none');

    if (nearBottom) {
        element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    return true;
}


async function markChatRead(chat) {
    if (!chat || document.visibilityState !== 'visible') {
        return;
    }

    try {
        const response = await fetch(chat.dataset.readUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.ok) {
            document.querySelectorAll('[data-chat-unread]').forEach((element) => {
                element.classList.add('d-none');
            });
        }
    } catch {
        // O indicador persistido será corrigido no próximo carregamento.
    }
}

function scheduleMarkRead(chat, messages) {
    const hasIncoming = messages.some((message) => Number(message.sender_id) !== Number(chatState.userId));

    if (!hasIncoming) {
        return;
    }

    window.clearTimeout(chatState.readTimer);
    chatState.readTimer = window.setTimeout(() => markChatRead(chat), 300);
}

function replaceFragment(documentFragment, selector) {
    const current = document.querySelector(selector);
    const incoming = documentFragment.querySelector(selector);

    if (current && incoming) {
        current.replaceWith(incoming);
    }
}

async function refreshState(chat) {
    if (!chat || chatState.refreshing) {
        return;
    }

    chatState.refreshing = true;

    try {
        const response = await fetch(chat.dataset.pageUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Falha ao atualizar a consulta (${response.status}).`);
        }

        const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');

        ['#chat', '#consultation-progress', '#consultation-actions', '#consultation-status-summary'].forEach((selector) => {
            replaceFragment(documentFragment, selector);
        });

        const refreshed = currentChat();

        if (refreshed) {
            bindChatForms(refreshed);
            bindChatPreferences(refreshed);
            applyChatPreferences(refreshed);
            setConnection(refreshed, chatState.connection);
            setNotice(refreshed, 'O estado da consulta foi atualizado.');

            /*
             * A consulta acabou ou a página deixou de ser a mais recente do
             * histórico: não há mais nada para descobrir, então o agendador é
             * desligado em vez de continuar consumindo requisições.
             */
            if (FINAL_STATUSES.has(refreshed.dataset.status) || refreshed.dataset.firstPage !== '1') {
                stopPolling();
            }

            if (refreshed.dataset.firstPage === '1') {
                syncMessages(refreshed);
            }
        }
    } catch {
        setNotice(chat, 'Não foi possível atualizar o estado da consulta. O histórico será recarregado na próxima sincronização.');
    } finally {
        chatState.refreshing = false;
    }
}

async function syncMessages(chat = currentChat()) {
    /*
     * Devolve o resultado ao agendador: `error` aumenta a espera antes da
     * próxima tentativa, `skipped` (aba antiga do histórico ou leitura já em
     * andamento) não conta como falha, `ok` volta ao ritmo normal.
     */
    if (!chat || chat.dataset.firstPage !== '1' || chatState.syncing) {
        return 'skipped';
    }

    chatState.syncing = true;
    const incoming = [];

    /*
     * Mensagens que já estavam na tela definem a linha de base: o que o
     * servidor devolver a partir daqui é novo e merece aviso sonoro. Com a
     * linha do tempo vazia a busca traz o histórico inicial, que fica silencioso.
     */
    const baselineId = lastMessageId(chat);

    try {
        for (let page = 0; page < MAX_BACKFILL_PAGES; page += 1) {
            const url = new URL(chat.dataset.feedUrl, window.location.origin);
            url.searchParams.set('after_id', String(lastMessageId(chat)));

            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`Falha ao recuperar mensagens (${response.status}).`);
            }

            const payload = await response.json();

            /*
             * Mudança de estado (aceite, encerramento, interrupção): `handleState`
             * leva à tela de atendimento quando o aceite chega fora dela e
             * atualiza o bloco quando já estamos no lugar certo.
             */
            if (payload.consultation && chat.dataset.status !== payload.consultation.status) {
                handleState({ consultation: payload.consultation });

                return 'ok';
            }

            (payload.messages ?? []).forEach((message) => {
                if (insertMessage(chat, message)) {
                    incoming.push(message);
                }
            });

            if (!payload.has_more) {
                break;
            }
        }

        if (incoming.length > 0) {
            setNotice(chat, '');
            scheduleMarkRead(chat, incoming);

            /*
             * Uma única chamada por lote: o backfill pode trazer várias
             * mensagens perdidas e o aviso sonoro deve soar apenas uma vez.
             */
            if (baselineId > 0 && incoming.some((message) => Number(message.sender_id) !== Number(chatState.userId))) {
                playIncomingSound();
            }
        }
    } catch {
        setNotice(chat, 'Não foi possível sincronizar as mensagens. Tentaremos novamente em instantes.');

        return 'error';
    } finally {
        chatState.syncing = false;
    }

    return 'ok';
}

function handleMessage(event) {
    const chat = currentChat();

    if (!chat || chat.dataset.firstPage !== '1' || !event?.message) {
        return;
    }

    const message = event.message;
    const lastId = lastMessageId(chat);

    if (Number(message.id) === lastId + 1) {
        if (insertMessage(chat, message)) {
            notifyIncoming(message);
            scheduleMarkRead(chat, [message]);
        }

        return;
    }

    if (Number(message.id) > lastId) {
        syncMessages(chat);
    }
}

function setFormError(form, message) {
    const error = form?.querySelector('[data-chat-error]');

    form?.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));

    if (!error) {
        return;
    }

    error.textContent = message ?? '';
    error.classList.toggle('d-none', !message);
}

function setFormBusy(form, busy) {
    const submit = form?.querySelector('[type="submit"]');

    if (!submit) {
        return;
    }

    if (busy) {
        submit.dataset.label = submit.innerHTML;
        submit.innerHTML = 'Enviando…';
        submit.disabled = true;

        return;
    }

    if (submit.dataset.label) {
        submit.innerHTML = submit.dataset.label;
        delete submit.dataset.label;
    }

    submit.disabled = false;
}

/*
 * Envio sem recarregar a página.
 *
 * A rota devolve a mensagem já persistida (JSON) quando o cabeçalho `Accept` é
 * `application/json`; o formulário HTML continua funcionando como antes para
 * navegadores sem JavaScript. O broadcast do Reverb chega depois do commit e é
 * descartado pelo `insertMessage`, que ignora IDs já exibidos.
 */
async function submitChatForm(form) {
    const chat = currentChat();

    if (!chat || chatState.sending) {
        return;
    }

    const body = form.querySelector('textarea[name="body"]');
    const card = form.querySelector('input[name="tarot_card_id"]:checked');

    if (body && !body.value.trim()) {
        setFormError(form, 'Escreva uma mensagem antes de enviar.');
        body.focus();

        return;
    }

    if (form.dataset.chatForm === 'card' && !card) {
        setFormError(form, 'Escolha uma carta antes de enviar.');
        form.querySelector('input[name="tarot_card_id"]')?.focus();

        return;
    }

    chatState.sending = true;
    setFormError(form, '');
    setFormBusy(form, true);

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
        });

        if (response.status === 419) {
            setFormError(form, 'Sua sessão expirou. Recarregue a página para continuar.');

            return;
        }

        if (response.status === 429) {
            setFormError(form, 'Você está enviando mensagens muito rápido. Aguarde alguns segundos.');

            return;
        }

        if (response.status === 422) {
            const payload = await response.json().catch(() => ({}));
            const firstError = Object.values(payload.errors ?? {}).flat()[0];

            setFormError(form, firstError ?? payload.message ?? 'Não foi possível enviar a mensagem.');
            form.querySelector('.is-invalid')?.focus();

            return;
        }

        if (!response.ok) {
            throw new Error(`Falha ao enviar (${response.status}).`);
        }

        const payload = await response.json();
        const current = currentChat();

        if (payload.message) {
            insertMessage(current, payload.message);
        }

        form.reset();
        setNotice(current, payload.notice ?? 'Mensagem enviada.');
        window.setTimeout(() => setNotice(currentChat(), ''), 4000);

        (form.querySelector('textarea[name="body"]') ?? form.querySelector('input[name="tarot_card_id"]'))?.focus();
    } catch {
        setFormError(form, 'Não foi possível enviar agora. Verifique a conexão e tente novamente.');
    } finally {
        chatState.sending = false;
        setFormBusy(form, false);
    }
}

/*
 * Os formulários são religados depois de cada atualização de fragmento, porque
 * `refreshState` substitui o bloco inteiro do chat.
 */
function bindChatForms(root = document) {
    root.querySelectorAll('[data-chat-form]').forEach((form) => {
        if (form.dataset.chatFormBound === '1') {
            return;
        }

        form.dataset.chatFormBound = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitChatForm(form);
        });

        form.querySelector('textarea')?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            /*
             * Ctrl/Cmd+Enter envia sempre. Sem modificador vale a preferência do
             * usuário: Enter envia (Shift+Enter quebra linha) ou, com a
             * preferência desligada, Enter quebra linha e o envio é explícito.
             */
            if (event.metaKey || event.ctrlKey) {
                event.preventDefault();
                form.requestSubmit();

                return;
            }

            if (!chatState.sendOnEnter || event.shiftKey) {
                return;
            }

            event.preventDefault();
            form.requestSubmit();
        });
    });
}

function handleState(event) {
    const chat = currentChat();
    const consultation = event?.consultation;

    if (!chat || !consultation || chat.dataset.status === consultation.status) {
        return;
    }

    /*
     * Aceite (ou reabertura) recebido fora da área de atendimento: o cliente e o
     * atendente são levados direto para a tela dedicada, sem novo login e sem
     * recarregar a lista inteira de consultas.
     */
    if (consultation.status === 'active' && chat.dataset.workspace !== '1') {
        window.location.assign(chat.dataset.workspaceUrl);

        return;
    }

    refreshState(chat);
}

function initializeRealtime() {
    const chat = currentChat();

    if (!chat || chatState.consultationId !== null) {
        return;
    }

    chatState.userId = Number(chat.dataset.currentUserId);
    chatState.consultationId = Number(chat.dataset.consultationId);

    /*
     * Preferências persistidas no perfil. O atributo só chega como `0` quando o
     * usuário desligou o comportamento; qualquer outro valor liga.
     */
    chatState.sendOnEnter = chat.dataset.sendOnEnter !== '0';
    chatState.soundEnabled = chat.dataset.soundEnabled !== '0';

    bindChatForms(chat);
    bindChatPreferences(chat);
    applyChatPreferences(chat);

    // O áudio só é liberado pelo navegador depois da primeira interação.
    document.addEventListener('pointerdown', primeChatAudio, { once: true });
    document.addEventListener('keydown', primeChatAudio, { once: true });

    /*
     * O histórico já está renderizado pelo servidor neste ponto, então tudo que
     * chegar daqui em diante é mensagem nova e pode ser anunciado.
     */
    chatState.soundArmed = true;

    if (chat.dataset.firstPage !== '1') {
        setNotice(chat, 'Esta é uma página antiga do histórico. Volte à página mais recente para acompanhar novas mensagens em tempo real.');
    }

    /*
     * Como o navegador recebe os avisos é decidido pelo servidor e publicado em
     * `data-realtime-mode`: `socket` quando há broadcaster WebSocket
     * configurado, `poll` quando não há processo persistente (hospedagem
     * compartilhada). Nada aqui depende de `VITE_*`, então o mesmo bundle
     * compilado atende os dois ambientes.
     */
    chatState.realtimeMode = chat.dataset.realtimeMode === 'poll' ? 'poll' : 'socket';

    bindConnectionWatchers();

    const mode = chatState.realtimeMode;
    const echo = mode === 'socket' ? window.initializeEcho?.() : null;

    if (!echo) {
        if (mode === 'poll') {
            setConnection(chat, 'polling');
            setNotice(chat, '');
        } else {
            setConnection(chat, 'unavailable');
            setNotice(chat, 'Tempo real indisponível. As mensagens serão buscadas pelo servidor periodicamente.');
        }

        startPolling();

        return;
    }

    echo.private(`consultation.${chatState.consultationId}`)
        .listen('.consultation.message', handleMessage)
        .listen('.consultation.state', handleState)
        .error(() => {
            const current = currentChat();

            setConnection(current, 'unavailable');
            setNotice(current, 'Não foi possível autorizar o canal em tempo real. O histórico continua disponível.');
        });

    const connection = echo.connector?.pusher?.connection;

    if (connection) {
        const updateConnection = () => {
            const current = currentChat();
            const status = echo.connectionStatus();

            setConnection(current, status);

            if (status === 'connected') {
                syncMessages(current);
            }
        };

        ['state_change', 'connected', 'disconnected', 'unavailable', 'failed'].forEach((eventName) => {
            connection.bind(eventName, updateConnection);
        });

        updateConnection();
    }

    startPolling();
}

/*
 * Avisos de conectividade que não dependem do agendador.
 *
 * O agendador já reage a `visibilitychange` e `online`; aqui só restam os
 * efeitos de interface: marcar a conversa como lida ao voltar para a aba e
 * refletir a perda/volta de rede no indicador.
 */
function bindConnectionWatchers() {
    document.addEventListener('visibilitychange', () => {
        const chat = currentChat();

        if (document.visibilityState === 'visible' && chat?.dataset.firstPage === '1') {
            markChatRead(chat);
        }
    });

    window.addEventListener('online', () => {
        setConnection(currentChat(), chatState.realtimeMode === 'poll' ? 'polling' : 'connecting');
    });

    window.addEventListener('offline', () => {
        setConnection(currentChat(), 'offline');
    });
}

/*
 * Leituras periódicas do chat.
 *
 * No modo `poll` esta é a via principal: o servidor publica os intervalos em
 * `data-poll-*` (2 s na aba visível, 10 s na aba oculta por padrão). No modo
 * `socket` ela continua existindo como rede de segurança lenta e só consulta
 * quando o canal não está conectado.
 */
function startPolling() {
    const chat = currentChat();

    if (chatState.poller !== null || !chat) {
        return;
    }

    // Páginas antigas do histórico não recebem nada novo: nada para consultar.
    if (chat.dataset.firstPage !== '1') {
        return;
    }

    const polling = chatState.realtimeMode === 'poll';

    chatState.poller = createPoller({
        intervalMs: polling
            ? readMilliseconds(chat.dataset.pollIntervalMs, 2000)
            : SOCKET_SAFETY_INTERVAL_MS,
        hiddenIntervalMs: polling
            ? readMilliseconds(chat.dataset.pollHiddenIntervalMs, 10000)
            : SOCKET_SAFETY_INTERVAL_MS,
        maxBackoffMs: readMilliseconds(chat.dataset.pollMaxBackoffMs, 30000),
        run: async () => {
            // Com o canal conectado, o broadcast já entregou a mensagem.
            if (!polling && chatState.connection === 'connected') {
                return;
            }

            const result = await syncMessages(currentChat());

            if (result === 'error') {
                throw new Error('Falha ao sincronizar mensagens.');
            }
        },
    });

    chatState.poller.start();
}

function stopPolling() {
    chatState.poller?.stop();
    chatState.poller = null;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeRealtime);
} else {
    initializeRealtime();
}

