/**
 * Canal privado do usuário (`user.{id}`) nos painéis.
 *
 * O Reverb entrega aqui os avisos que não pertencem à conversa aberta:
 *  - `consultation.request`: um cliente solicitou o atendente. A sirene toca,
 *    o modal de aceite/recusa abre e a fila é atualizada sem recarregar. O
 *    bloco que registra o canal fica no layout do painel, então o aviso aparece
 *    em qualquer tela do atendente — painel, histórico, perfil ou consulta
 *    aberta — e não só no dashboard.
 *  - `consultation.state`: o aceite acontece. Cliente e atendente são levados
 *    direto para a tela dedicada de atendimento; estados finais limpam a fila.
 *
 * Nada é decidido no navegador: aceitar/recusar chama as rotas do servidor, que
 * revalidam participação, estado e disponibilidade. Se o canal cair, o painel
 * ressincroniza por HTTP a cada reconexão (`data-events-url`).
 *
 * Sem WebSocket — hospedagem compartilhada, modo `data-realtime-mode="poll"` —
 * o mesmo `data-events-url` passa a ser lido em intervalos curtos e vira a via
 * principal: ele devolve a fila do atendente e a consulta aberta do usuário,
 * então o modal de aceite, a sirene e a navegação para o atendimento continuam
 * funcionando por HTTP. Toda leitura é somente leitura no servidor: não marca
 * atividade, presença, mensagens lidas nem cobrança.
 */

import { Modal } from 'bootstrap';

import { createPoller, readMilliseconds } from './polling';

// Ritmo do alerta: cada toque dura ALERT_SOUND_DURATION_MS e o conjunto se
// repete até o pedido ser respondido, a janela ser fechada ou ALERT_MAX_MS
// estourar. O som é escolhido por atendente no painel administrativo.
const ALERT_REPEAT_MS = 2400;
const ALERT_MAX_MS = 90000;
const ALERT_SOUND_DURATION_MS = 1500;
const ALERT_GAIN = 0.12;
const CUSTOM_SOUND_GAIN = 0.8;
const CUSTOM_FALLBACK_REPEAT_MS = 5500;
const DEFAULT_REQUEST_SOUND = 'siren';
const CUSTOM_REQUEST_SOUND = 'custom';
const REQUEST_SOUNDS = new Set([DEFAULT_REQUEST_SOUND, 'bell', 'chime', 'pulse', CUSTOM_REQUEST_SOUND]);
const SIREN_LOW_HZ = 640;
const SIREN_HIGH_HZ = 1180;

const userChannelState = {
    root: null,
    echo: null,
    userId: null,
    realtimeMode: 'socket',
    poller: null,
    lastState: null,
    audio: null,
    sound: DEFAULT_REQUEST_SOUND,
    soundUrl: null,
    customBuffer: null,
    customLoadPromise: null,
    alertNodes: [],
    alertTimer: null,
    alertStopTimer: null,
    modal: null,
    currentId: null,
    requests: new Map(),
    syncing: false,
    deciding: false,
    leaving: false,
};

function userChannelRoot() {
    return document.querySelector('[data-user-channel]');
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function requestModal() {
    return document.querySelector('[data-request-modal]');
}

function requestsList() {
    return document.querySelector('[data-requests-list]');
}

function setUserNotice(message) {
    const notice = document.querySelector('[data-user-notice]');

    if (!notice) {
        return;
    }

    notice.textContent = message ?? '';
    notice.classList.toggle('d-none', !message);
}

function setRequestError(message) {
    const error = requestModal()?.querySelector('[data-request-error]');

    if (!error) {
        return;
    }

    error.textContent = message ?? '';
    error.classList.toggle('d-none', !message);
}

function updatePendingCount() {
    const total = userChannelState.requests.size;

    document.querySelectorAll('[data-pending-count]').forEach((element) => {
        element.textContent = String(total);
    });

    document.querySelectorAll('[data-requests-badge]').forEach((element) => {
        element.classList.toggle('d-none', total === 0);
    });
}

function workspaceUrlFor(consultationId) {
    const template = userChannelRoot()?.dataset.workspaceUrl;

    return template ? template.replace('__ID__', String(consultationId)) : '';
}

function leaveTo(url) {
    if (!url || userChannelState.leaving) {
        return;
    }

    userChannelState.leaving = true;
    window.clearTimeout(userChannelState.alertTimer);
    window.location.assign(url);
}

/*
 * Alerta sonoro de nova solicitação.
 *
 * Os toques predefinidos são sintetizados no próprio navegador. Quando a
 * administração envia um MP3, o arquivo é baixado uma vez e decodificado no
 * AudioContext para tocar junto com o mesmo mecanismo de repetição. A sirene
 * continua sendo o padrão histórico. Navegadores bloqueiam áudio antes de uma
 * interação do usuário, por isso o contexto é preparado no primeiro
 * clique/tecla da página; até lá o alerta fica agendado e começa no primeiro
 * gesto.
 */
function normalizeRequestSound(value) {
    return REQUEST_SOUNDS.has(value) ? value : DEFAULT_REQUEST_SOUND;
}

/**
 * Baixa e decodifica o MP3 uma única vez por URL. Se o download falhar, o
 * chamador simplesmente não toca o arquivo e o alerta visual continua ativo.
 */
function loadCustomSound(url) {
    if (!url) {
        return Promise.resolve(null);
    }

    if (userChannelState.customBuffer && userChannelState.soundUrl === url) {
        return Promise.resolve(userChannelState.customBuffer);
    }

    if (userChannelState.customLoadPromise && userChannelState.soundUrl === url) {
        return userChannelState.customLoadPromise;
    }

    const context = primeAudio();

    if (!context) {
        return Promise.resolve(null);
    }

    userChannelState.soundUrl = url;
    userChannelState.customBuffer = null;

    const promise = fetch(url, { credentials: 'same-origin' })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Falha ao baixar o som (${response.status}).`);
            }

            return response.arrayBuffer();
        })
        .then((data) => new Promise((resolve, reject) => {
            context.decodeAudioData(data, resolve, reject);
        }))
        .then((buffer) => {
            if (userChannelState.soundUrl === url) {
                userChannelState.customBuffer = buffer;
            }

            return buffer;
        })
        .catch(() => null)
        .finally(() => {
            if (userChannelState.customLoadPromise === promise) {
                userChannelState.customLoadPromise = null;
            }
        });

    userChannelState.customLoadPromise = promise;

    return promise;
}

function primeAudio() {
    const AudioContextClass = window.AudioContext ?? window.webkitAudioContext;

    if (!AudioContextClass) {
        return null;
    }

    userChannelState.audio ??= new AudioContextClass();

    if (userChannelState.audio.state === 'suspended') {
        userChannelState.audio.resume().catch(() => {});
    }

    return userChannelState.audio;
}

function scheduleTone(context, {
    frequency,
    start,
    duration,
    gain = ALERT_GAIN,
    type = 'sine',
    endFrequency = null,
    filterFrequency = null,
}) {
    const oscillator = context.createOscillator();
    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, start);

    if (endFrequency !== null) {
        oscillator.frequency.exponentialRampToValueAtTime(endFrequency, start + duration);
    }

    const envelope = context.createGain();
    envelope.gain.setValueAtTime(0.0001, start);
    envelope.gain.exponentialRampToValueAtTime(gain, start + 0.02);
    envelope.gain.setValueAtTime(gain, start + Math.max(0.03, duration - 0.12));
    envelope.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    let filter = null;

    if (filterFrequency !== null) {
        filter = context.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = filterFrequency;
        oscillator.connect(filter).connect(envelope).connect(context.destination);
    } else {
        oscillator.connect(envelope).connect(context.destination);
    }

    oscillator.start(start);
    oscillator.stop(start + duration);

    const node = { oscillator, gain: envelope, filter };

    userChannelState.alertNodes.push(node);
    oscillator.addEventListener('ended', () => {
        userChannelState.alertNodes = userChannelState.alertNodes.filter((item) => item !== node);
        oscillator.disconnect();
        filter?.disconnect();
        envelope.disconnect();
    });
}

function playSiren() {
    const context = userChannelState.audio;

    if (!context || context.state !== 'running') {
        return;
    }

    const start = context.currentTime;
    const duration = ALERT_SOUND_DURATION_MS / 1000;
    const quarter = duration / 4;

    const gain = context.createGain();
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(ALERT_GAIN, start + 0.08);
    gain.gain.setValueAtTime(ALERT_GAIN, start + duration - 0.14);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    const filter = context.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = 2200;

    const oscillator = context.createOscillator();
    oscillator.type = 'sawtooth';
    oscillator.frequency.setValueAtTime(SIREN_LOW_HZ, start);
    oscillator.frequency.linearRampToValueAtTime(SIREN_HIGH_HZ, start + quarter);
    oscillator.frequency.linearRampToValueAtTime(SIREN_LOW_HZ, start + quarter * 2);
    oscillator.frequency.linearRampToValueAtTime(SIREN_HIGH_HZ, start + quarter * 3);
    oscillator.frequency.linearRampToValueAtTime(SIREN_LOW_HZ, start + quarter * 4);

    oscillator.connect(filter).connect(gain).connect(context.destination);
    oscillator.start(start);
    oscillator.stop(start + duration);

    const node = { oscillator, gain, filter };

    userChannelState.alertNodes.push(node);
    oscillator.addEventListener('ended', () => {
        userChannelState.alertNodes = userChannelState.alertNodes.filter((item) => item !== node);
        oscillator.disconnect();
        filter.disconnect();
        gain.disconnect();
    });
}

function playBell() {
    const context = userChannelState.audio;
    const start = context.currentTime;

    scheduleTone(context, { frequency: 880, start, duration: 0.55, gain: 0.16 });
    scheduleTone(context, { frequency: 660, start: start + 0.55, duration: 0.7, gain: 0.16 });
}

function playChime() {
    const context = userChannelState.audio;
    const start = context.currentTime;

    scheduleTone(context, { frequency: 523.25, start, duration: 0.55, gain: 0.14 });
    scheduleTone(context, { frequency: 659.25, start: start + 0.35, duration: 0.55, gain: 0.14 });
    scheduleTone(context, { frequency: 783.99, start: start + 0.7, duration: 0.7, gain: 0.14 });
}

function playPulse() {
    const context = userChannelState.audio;
    const start = context.currentTime;

    scheduleTone(context, { frequency: 880, start, duration: 0.18, gain: 0.14 });
    scheduleTone(context, { frequency: 880, start: start + 0.3, duration: 0.18, gain: 0.14 });
}

function playCustomBuffer(buffer) {
    const context = userChannelState.audio;

    if (!context || context.state !== 'running' || !buffer) {
        return false;
    }

    const source = context.createBufferSource();
    source.buffer = buffer;

    const gain = context.createGain();
    gain.gain.value = CUSTOM_SOUND_GAIN;

    source.connect(gain).connect(context.destination);
    source.start();

    // `stopAlert` encerra qualquer nó pelo campo `oscillator`; o buffer source
    // expõe a mesma operação `stop()`.
    const node = { oscillator: source, gain, filter: null };

    userChannelState.alertNodes.push(node);
    source.addEventListener('ended', () => {
        userChannelState.alertNodes = userChannelState.alertNodes.filter((item) => item !== node);
        source.disconnect();
        gain.disconnect();
    });

    return true;
}

function playCustomRequestSound(url) {
    const context = userChannelState.audio;

    if (!context || context.state !== 'running' || !url) {
        return;
    }

    if (userChannelState.customBuffer && userChannelState.soundUrl === url) {
        playCustomBuffer(userChannelState.customBuffer);
        return;
    }

    loadCustomSound(url).then((buffer) => {
        if (buffer && userChannelState.audio?.state === 'running') {
            playCustomBuffer(buffer);
        }
    });
}

function playSyntheticRequestSound(value) {
    switch (value) {
        case 'bell':
            playBell();
            break;
        case 'chime':
            playChime();
            break;
        case 'pulse':
            playPulse();
            break;
        default:
            playSiren();
    }
}

function playRequestSound(value) {
    const context = userChannelState.audio;

    if (!context || context.state !== 'running') {
        return;
    }

    const sound = normalizeRequestSound(value);

    if (sound === CUSTOM_REQUEST_SOUND) {
        playCustomRequestSound(userChannelState.soundUrl);
        return;
    }

    playSyntheticRequestSound(sound);
}

function alertRepeatMs() {
    if (userChannelState.sound !== CUSTOM_REQUEST_SOUND) {
        return ALERT_REPEAT_MS;
    }

    if (userChannelState.customBuffer) {
        return Math.max(
            ALERT_REPEAT_MS,
            Math.ceil(userChannelState.customBuffer.duration * 1000) + 400,
        );
    }

    // Ainda carregando: espera o limite máximo do arquivo para não sobrepor
    // duas reproduções caso o download termine durante o primeiro toque.
    return CUSTOM_FALLBACK_REPEAT_MS;
}

function scheduleNextAlert() {
    userChannelState.alertTimer = window.setTimeout(() => {
        if (userChannelState.alertTimer === null) {
            return;
        }

        playRequestSound(userChannelState.sound);
        scheduleNextAlert();
    }, alertRepeatMs());
}

function startAlert() {
    if (userChannelState.alertTimer !== null) {
        return;
    }

    primeAudio();
    playRequestSound(userChannelState.sound);
    scheduleNextAlert();

    userChannelState.alertStopTimer = window.setTimeout(stopAlert, ALERT_MAX_MS);
}

function stopAlert() {
    window.clearTimeout(userChannelState.alertTimer);
    window.clearTimeout(userChannelState.alertStopTimer);
    userChannelState.alertTimer = null;
    userChannelState.alertStopTimer = null;

    // Corta o toque em curso: o pedido foi respondido ou o alerta silenciado.
    userChannelState.alertNodes.forEach(({ oscillator }) => {
        try {
            oscillator.stop();
        } catch {
            // Oscilador já encerrado pelo agendamento original.
        }
    });
    userChannelState.alertNodes = [];
}

/**
 * Prévia usada no cadastro administrativo de atendentes.
 *
 * O clique no botão "Ouvir" é a interação que libera o AudioContext. Se o
 * navegador ainda estiver retomando o contexto, o toque é agendado para logo
 * depois do `resume`.
 */
export function previewRequestSound(value, url = null) {
    const context = primeAudio();

    if (!context) {
        return;
    }

    const sound = normalizeRequestSound(value);
    const play = () => {
        if (sound === CUSTOM_REQUEST_SOUND) {
            playCustomRequestSound(url ?? userChannelState.soundUrl);
            return;
        }

        playSyntheticRequestSound(sound);
    };

    if (context.state === 'running') {
        play();
        return;
    }

    context.resume().then(play).catch(() => {});
}

function buildRequestCard(request) {
    const item = document.createElement('li');
    item.className = 'panel-list__item';
    item.dataset.requestId = String(request.id);

    const info = document.createElement('div');
    const title = document.createElement('p');
    title.className = 'panel-list__title';
    title.textContent = request.client_name;
    const meta = document.createElement('p');
    meta.className = 'panel-list__meta';
    meta.textContent = `${request.modality_label} · ${request.price_per_minute_label ?? '—'}/min · solicitada em ${request.requested_at_label}`;
    info.append(title, meta);

    const actions = document.createElement('div');
    actions.className = 'd-flex flex-wrap align-items-center gap-2';

    const details = document.createElement('a');
    details.className = 'btn btn-ghost btn-sm';
    details.href = request.show_url;
    details.textContent = 'Ver detalhes';

    const respond = document.createElement('button');
    respond.type = 'button';
    respond.className = 'btn btn-primary btn-sm';
    respond.textContent = 'Responder agora';
    respond.addEventListener('click', () => openRequestModal(request.id));

    actions.append(details, respond);
    item.append(info, actions);

    return item;
}

/*
 * Registra o pedido no estado e, quando a tela atual tem fila (dashboard do
 * atendente), atualiza o cartão correspondente. Nas outras telas só o estado
 * existe — é dele que o modal e a sirene vivem, por isso a fila não pode ser
 * pré-requisito.
 */
function addRequest(request) {
    if (!request?.id) {
        return;
    }

    const key = Number(request.id);

    userChannelState.requests.set(key, request);

    const list = requestsList();

    if (list) {
        const existing = list.querySelector(`[data-request-id="${key}"]`);
        const card = buildRequestCard(request);

        if (existing) {
            existing.replaceWith(card);
        } else {
            list.append(card);
        }

        list.classList.remove('d-none');
        document.querySelector('[data-requests-empty]')?.classList.add('d-none');
    }

    updatePendingCount();
}

function removeRequest(requestId) {
    const key = Number(requestId);

    userChannelState.requests.delete(key);
    requestsList()?.querySelector(`[data-request-id="${key}"]`)?.remove();

    if (userChannelState.requests.size === 0) {
        requestsList()?.classList.add('d-none');
        document.querySelector('[data-requests-empty]')?.classList.remove('d-none');
        stopAlert();
    }

    updatePendingCount();

    if (userChannelState.currentId === key) {
        userChannelState.currentId = null;

        // Só fecha quando não há outro pedido para assumir o modal.
        if (userChannelState.requests.size === 0) {
            userChannelState.modal?.hide();
        }
    }
}


function fillRequestModal(request) {
    const element = requestModal();

    if (!element) {
        return;
    }

    const client = element.querySelector('[data-request-client]');
    const meta = element.querySelector('[data-request-meta]');

    if (client) {
        client.textContent = request.client_name;
    }

    if (meta) {
        meta.textContent = `${request.modality_label} · ${request.price_per_minute_label ?? '—'}/min · solicitada em ${request.requested_at_label}`;
    }

    setRequestError('');
    element.querySelectorAll('[data-request-accept], [data-request-reject]').forEach((button) => {
        button.disabled = false;
    });

    userChannelState.modal = Modal.getOrCreateInstance(element);
    userChannelState.modal.show();
}

function openRequestModal(requestId) {
    const request = userChannelState.requests.get(Number(requestId));

    if (!request) {
        return;
    }

    userChannelState.currentId = Number(requestId);
    fillRequestModal(request);
}

/**
 * Abre o próximo pedido da fila.
 *
 * Quando o modal já está aberto o conteúdo é trocado no lugar: fechar e abrir
 * em seguida faria o Bootstrap ignorar a segunda abertura (`_isTransitioning`).
 */
function openNextRequest() {
    const next = [...userChannelState.requests.keys()][0];

    if (next === undefined) {
        userChannelState.currentId = null;
        userChannelState.modal?.hide();

        return;
    }

    openRequestModal(next);
}

async function decide(action) {
    const request = userChannelState.requests.get(userChannelState.currentId);
    const element = requestModal();

    if (!request || !element || userChannelState.deciding) {
        return;
    }

    const url = action === 'accept' ? request.accept_url : request.reject_url;
    const buttons = element.querySelectorAll('[data-request-accept], [data-request-reject]');

    userChannelState.deciding = true;
    buttons.forEach((button) => {
        button.disabled = true;
    });
    setRequestError('');

    try {
        const response = await fetch(url, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const payload = await response.json().catch(() => ({}));

        /*
         * 403/409/410 significam que outra aba, outro atendente ou o cliente
         * chegaram primeiro. O pedido sai da fila e a lista é ressincronizada
         * com o servidor, que é a fonte de verdade.
         */
        if (response.status === 403 || response.status === 409 || response.status === 410) {
            setRequestError(payload.message ?? 'Esta solicitação não está mais disponível.');
            removeRequest(request.id);
            syncPending();

            return;
        }

        if (response.status === 422) {
            const firstError = Object.values(payload.errors ?? {}).flat()[0];
            setRequestError(firstError ?? payload.message ?? 'Não foi possível responder agora.');

            return;
        }

        if (!response.ok) {
            throw new Error(`Falha ao responder a solicitação (${response.status}).`);
        }

        stopAlert();
        removeRequest(request.id);

        if (action === 'accept' && payload.redirect) {
            leaveTo(payload.redirect);

            return;
        }

        setUserNotice(payload.message ?? 'Solicitação recusada.');
        window.setTimeout(() => setUserNotice(''), 6000);
        openNextRequest();
    } catch {
        setRequestError('Não foi possível responder agora. Verifique a conexão e tente novamente.');
    } finally {
        userChannelState.deciding = false;
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}


/*
 * Ressincronização da fila.
 *
 * Roda na abertura do painel e a cada reconexão do canal privado, para
 * recuperar pedidos feitos enquanto a aba estava fechada e remover da tela os
 * que foram aceitos, recusados ou cancelados em outra aba ou por outra pessoa.
 *
 * `data-events-url` traz a fila e a consulta aberta; `data-pending-url` (só do
 * atendente) traz apenas a fila. O primeiro é usado quando existe porque a
 * mesma leitura também revela o aceite no modo polling.
 *
 * Devolve `ok`, `skipped` ou `error` para o agendador decidir o ritmo.
 */
async function syncPending({ notifyWhenNew = false } = {}) {
    const root = userChannelRoot();
    const url = root?.dataset.eventsUrl || root?.dataset.pendingUrl;

    if (!url || userChannelState.syncing) {
        return 'skipped';
    }

    userChannelState.syncing = true;

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Falha ao listar solicitações (${response.status}).`);
        }

        const payload = await response.json();

        applyRequests(payload.requests ?? [], { notifyWhenNew });

        if (payload.consultation) {
            applyConsultationState(payload.consultation);
        }

        return 'ok';
    } catch {
        // Silencioso: a próxima leitura tenta novamente com espera maior.
        return 'error';
    } finally {
        userChannelState.syncing = false;
    }
}

/*
 * Reconcilia a fila exibida com a lista devolvida pelo servidor: some com o que
 * foi respondido em outra aba, acrescenta o que é novo e, na primeira leitura
 * de cada pedido, abre o modal e arma a sirene.
 */
function applyRequests(requests, { notifyWhenNew = false } = {}) {
    const knownIds = new Set(requests.map((request) => Number(request.id)));

    [...userChannelState.requests.keys()]
        .filter((id) => !knownIds.has(id))
        .forEach((id) => removeRequest(id));

    let firstNew = null;

    requests.forEach((request) => {
        if (!userChannelState.requests.has(Number(request.id))) {
            firstNew ??= request;
        }

        addRequest(request);
    });

    if (notifyWhenNew && firstNew !== null && requestModal() !== null) {
        startAlert();
        openRequestModal(firstNew.id);
    }
}

/*
 * Aplica o estado da consulta aberta lido por HTTP.
 *
 * Só reage a transições: a primeira leitura apenas memoriza o estado atual, e
 * um estado repetido não dispara nada. Sem isso, cada leitura levaria o usuário
 * de volta para a tela de atendimento.
 */
function applyConsultationState(consultation) {
    const requestId = Number(consultation.id);
    const signature = `${requestId}:${consultation.status}`;

    if (userChannelState.requests.has(requestId)) {
        removeRequest(requestId);
    }

    if (userChannelState.lastState === null) {
        userChannelState.lastState = signature;

        return;
    }

    if (signature === userChannelState.lastState) {
        return;
    }

    userChannelState.lastState = signature;
    handleState({ consultation });
}

function handleRequest(event) {
    const request = event?.request;

    if (!request?.id) {
        return;
    }

    const isNew = !userChannelState.requests.has(Number(request.id));

    /*
     * Só o atendente tem modal para responder: no painel do cliente o evento
     * nem é emitido, mas se chegar não deve tocar sirene sem ação possível.
     */
    const canRespond = requestModal() !== null;

    addRequest(request);

    if (!isNew || !canRespond) {
        return;
    }

    startAlert();

    if (userChannelState.currentId === null) {
        openRequestModal(request.id);
    }
}

function handleState(event) {
    const consultation = event?.consultation;

    if (!consultation?.id) {
        return;
    }

    const requestId = Number(consultation.id);

    // Mantém a assinatura usada pelo modo polling em sincronia com o canal.
    userChannelState.lastState = `${requestId}:${consultation.status}`;

    if (userChannelState.requests.has(requestId)) {
        removeRequest(requestId);
    }

    /*
     * Aceite confirmado: cliente e atendente vão para a tela de atendimento
     * mesmo estando em outra página do painel.
     */
    if (consultation.status === 'active') {
        if (!document.querySelector('[data-consultation-workspace]')) {
            leaveTo(workspaceUrlFor(requestId));
        }

        return;
    }

    if (consultation.is_final && userChannelRoot()?.dataset.refreshOnFinal === '1') {
        window.location.reload();
    }
}

/*
 * Leituras periódicas do painel (modo `poll`).
 *
 * Fila de solicitações, sirene e navegação para o atendimento passam a depender
 * desta leitura. Na tela de conversa o próprio chat já busca mensagens e estado
 * a cada 2 s, então para o cliente o painel não lê de novo; para o atendente a
 * leitura continua, porque é ela que abre o modal de um pedido novo.
 */
function startPanelPolling() {
    const root = userChannelRoot();

    if (userChannelState.poller !== null || !root) {
        return;
    }

    const chat = document.querySelector('[data-chat]');
    const isAttendant = root.dataset.pendingUrl !== undefined;

    if (chat?.dataset.firstPage === '1' && !isAttendant) {
        return;
    }

    userChannelState.poller = createPoller({
        intervalMs: readMilliseconds(root.dataset.pollIntervalMs, 3000),
        hiddenIntervalMs: readMilliseconds(root.dataset.pollHiddenIntervalMs, 15000),
        maxBackoffMs: readMilliseconds(root.dataset.pollMaxBackoffMs, 30000),
        run: async () => {
            const result = await syncPending({ notifyWhenNew: true });

            if (result === 'error') {
                throw new Error('Falha ao atualizar a fila de solicitações.');
            }
        },
    });

    userChannelState.poller.start();
}

function initializeUserChannel() {
    const root = userChannelRoot();

    if (!root) {
        return;
    }

    userChannelState.root = root;
    userChannelState.userId = Number(root.dataset.userId);
    userChannelState.sound = normalizeRequestSound(root.dataset.requestSound);
    userChannelState.soundUrl = root.dataset.requestSoundUrl || null;

    const modal = requestModal();

    modal?.querySelector('[data-request-accept]')?.addEventListener('click', () => decide('accept'));
    modal?.querySelector('[data-request-reject]')?.addEventListener('click', () => decide('reject'));

    /*
     * Fechar no "X" silencia a sirene e libera o pedido atual: o próximo evento
     * de solicitação volta a abrir o modal e a tocar o alerta.
     */
    modal?.addEventListener('hidden.bs.modal', () => {
        userChannelState.currentId = null;
        stopAlert();
    });

    // Navegadores exigem interação do usuário antes de liberar áudio; só o
    // atendente (que tem modal) precisa da sirene.
    if (modal) {
        document.addEventListener('pointerdown', primeAudio, { once: true });
        document.addEventListener('keydown', primeAudio, { once: true });

        if (userChannelState.sound === CUSTOM_REQUEST_SOUND && userChannelState.soundUrl) {
            void loadCustomSound(userChannelState.soundUrl);
        }
    }

    const mode = root.dataset.realtimeMode === 'poll' ? 'poll' : 'socket';

    userChannelState.realtimeMode = mode;

    const echo = mode === 'socket' ? window.initializeEcho?.() : null;

    if (!echo) {
        if (mode === 'poll') {
            startPanelPolling();
        } else {
            setUserNotice('O aviso em tempo real está indisponível neste navegador. Atualize o painel para ver novas solicitações.');
        }

        return;
    }

    userChannelState.echo = echo;

    echo.private(`user.${userChannelState.userId}`)
        .listen('.consultation.request', handleRequest)
        .listen('.consultation.state', handleState)
        .error(() => setUserNotice('Não foi possível autorizar o canal de avisos em tempo real. Atualize o painel para ver novas solicitações.'));

    const connection = echo.connector?.pusher?.connection;

    connection?.bind('state_change', (states) => {
        if (states.current === 'connected' && states.previous !== 'connected') {
            syncPending({ notifyWhenNew: true });
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            syncPending({ notifyWhenNew: true });
        }
    });

    window.addEventListener('online', () => syncPending({ notifyWhenNew: true }));

    /*
     * A carga inicial também anuncia: o layout registra o canal em qualquer
     * tela, então um pedido feito antes de a página abrir (ou durante uma
     * navegação) precisa abrir o modal e armar a sirene mesmo sem evento novo.
     */
    syncPending({ notifyWhenNew: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUserChannel);
} else {
    initializeUserChannel();
}

