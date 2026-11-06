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
 *    direto para a tela dedicada de atendimento; estados finais limpam a fila e
 *    recarregam a tela (`data-refresh-on-final`), porque a recusa ou o
 *    encerramento mudam o que a página pode mostrar.
 *
 * No modo polling a mesma transição é detectada quando `panel/eventos` deixa de
 * devolver a consulta aberta (`handleClosedConsultation`).
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

// Tempo máximo para o servidor responder ao aceite/recusa. Sem esse limite uma
// conexão pendurada deixaria os dois botões desabilitados para sempre.
const DECISION_TIMEOUT_MS = 15000;

/*
 * Diagnóstico em produção.
 *
 * Este arquivo decide quando o modal abre, qual pedido ele representa e se a
 * sirene toca — tudo invisível na interface. Sem um rastro no console fica
 * impossível, no servidor compartilhado, separar "o evento não chegou" de "o
 * pedido saiu da fila" ou de "os botões estão presos". As linhas saem com o
 * prefixo `[atendimento]` e podem ser silenciadas com `?debug=0` na URL ou
 * `localStorage['arcana:channel-debug'] = '0'`.
 */
const CHANNEL_LOG_PREFIX = '[atendimento]';

function channelDebugEnabled() {
    try {
        if (new URLSearchParams(window.location.search).get('debug') === '0') {
            return false;
        }

        if (window.localStorage?.getItem('arcana:channel-debug') === '0') {
            return false;
        }
    } catch {
        // Modo privado/navegador sem storage: mantém o log ligado.
    }

    return true;
}

const channelDebug = channelDebugEnabled();

function channelLog(event, details = null) {
    if (!channelDebug) {
        return;
    }

    if (details === null) {
        console.info(`${CHANNEL_LOG_PREFIX} ${event}`);

        return;
    }

    console.info(`${CHANNEL_LOG_PREFIX} ${event}`, details);
}

/**
 * Nome do bundle em execução.
 *
 * Serve para confirmar, no navegador do cliente, qual versão do painel está
 * carregada — um `public/build` desatualizado no servidor é a primeira
 * suspeita quando uma correção "não faz efeito".
 */
function loadedBuildName() {
    const script = [...document.querySelectorAll('script[src]')]
        .map((element) => element.getAttribute('src') ?? '')
        .find((src) => /\/build\/assets\/.*\.js$/.test(src));

    return script ? script.split('/').pop() : 'desconhecido';
}

const userChannelState = {
    root: null,
    echo: null,
    userId: null,
    realtimeMode: 'socket',
    poller: null,
    lastState: null,
    lastConsultationId: null,
    audio: null,
    audioUnlockBound: false,
    audioUnlockListeners: [],
    sound: DEFAULT_REQUEST_SOUND,
    soundUrl: null,
    customBuffer: null,
    customLoadPromise: null,
    alertNodes: [],
    alertTimer: null,
    alertStopTimer: null,
    alertActive: false,
    alertLastPlayAt: 0,
    modal: null,
    modalFocusReturn: null,
    currentId: null,
    requests: new Map(),
    syncing: false,
    deciding: false,
    leaving: false,
    reloadingForSession: false,
    // Assinatura da última leitura aplicada: evita repetir log a cada poll.
    lastQueueSignature: null,
    // Evita repetir "sirene bloqueada" a cada repetição do toque.
    alertBlockedLogged: false,
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

/**
 * Reduz uma URL do servidor ao caminho relativo quando ela aponta para o mesmo
 * host da página.
 *
 * `route()` devolve URL absoluta. Atrás do proxy da hospedagem compartilhada o
 * Laravel pode montá-la com `http://` — o que o navegador bloqueia como mixed
 * content em páginas HTTPS, deixando o aceite/recusa sem resposta. Usar o
 * caminho relativo mantém o esquema e o host da própria página, que são sempre
 * os corretos, inclusive quando o TLS termina no proxy.
 */
function sameOriginUrl(url) {
    if (!url) {
        return '';
    }

    try {
        const parsed = new URL(url, window.location.origin);

        return parsed.origin === window.location.origin
            ? `${parsed.pathname}${parsed.search}`
            : parsed.toString();
    } catch {
        return url;
    }
}

/**
 * Cabeçalhos das chamadas autenticadas feitas por `fetch`.
 *
 * O token CSRF vem do `<meta>` do layout do painel; `X-Requested-With` faz o
 * servidor responder JSON em vez de redirecionar.
 */
function requestHeaders() {
    return {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
    };
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

/**
 * Aviso visível de áudio bloqueado pelo navegador.
 *
 * Só aparece enquanto existe alarme pendente e o `AudioContext` ainda não está
 * em `running`: com ele o atendente sabe que basta um clique para a sirene
 * tocar, em vez de achar que o aviso sonoro simplesmente não funciona.
 */
function updateAudioHint() {
    const hint = requestModal()?.querySelector('[data-request-audio-hint]');

    if (!hint) {
        return;
    }

    const blocked = userChannelState.alertActive && userChannelState.audio?.state !== 'running';
    hint.classList.toggle('d-none', !blocked);
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

    return template ? sameOriginUrl(template.replace('__ID__', String(consultationId))) : '';
}

/*
 * Aviso "Voltar ao atendimento".
 *
 * O bloco é montado no servidor (`layouts.partials.active-consultation-banner`)
 * para funcionar mesmo sem JavaScript. Aqui ele é reconciliado com o estado que
 * chega pelo canal privado ou pelo polling: quando o atendimento é encerrado o
 * aviso some, e se outra consulta ficar ativa o link é reapontado. A tela do
 * próprio atendimento nunca mostra o aviso — lá as pessoas já estão no chat.
 */
function consultationReturnBar() {
    return document.querySelector('[data-consultation-return]');
}

function syncConsultationReturn(consultation) {
    const bar = consultationReturnBar();

    if (!bar) {
        return;
    }

    if (document.querySelector('[data-consultation-workspace]') || consultation?.status !== 'active') {
        bar.classList.add('d-none');

        return;
    }

    const url = workspaceUrlFor(consultation.id);
    const link = bar.querySelector('[data-consultation-return-link]');

    if (link && url) {
        link.setAttribute('href', url);
    }

    bar.classList.remove('d-none');
}

function leaveTo(url) {
    const target = sameOriginUrl(url);

    if (!target || userChannelState.leaving) {
        return;
    }

    userChannelState.leaving = true;
    window.clearTimeout(userChannelState.alertTimer);
    window.location.assign(target);
}

/*
 * Alerta sonoro de nova solicitação.
 *
 * Os toques predefinidos são sintetizados no próprio navegador. Quando a
 * administração envia um MP3, o arquivo é baixado uma vez e decodificado no
 * AudioContext para tocar junto com o mesmo mecanismo de repetição. A sirene
 * continua sendo o padrão histórico.
 *
 * Navegadores só liberam áudio depois de uma interação do usuário — e o Chrome
 * avisa no console quando um `AudioContext` nasce antes disso. Por isso o
 * contexto nunca é criado no carregamento: os primeiros `pointerdown`,
 * `keydown` e `touchstart` da página criam e retomam o contexto e, no mesmo
 * instante em que ele passa a `running`, o alarme que estava aguardando toca.
 * Criar o contexto dentro do gesto também é o único caminho confiável no
 * Safari, que mantém suspenso um contexto nascido fora da interação.
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

    // Sem contexto liberado não há como decodificar: o arquivo é baixado no
    // primeiro gesto do usuário, quando `handleAudioReady` roda.
    const context = userChannelState.audio;

    if (!context) {
        return Promise.resolve(null);
    }

    userChannelState.soundUrl = url;
    userChannelState.customBuffer = null;

    const promise = fetch(sameOriginUrl(url), { credentials: 'same-origin' })
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

/**
 * Cria (uma única vez) e retoma o `AudioContext`.
 *
 * Só deve ser chamado a partir de um gesto do usuário: `pointerdown`,
 * `keydown`, `touchstart` ou o clique no botão de prévia do cadastro.
 */
function primeAudio() {
    const AudioContextClass = window.AudioContext ?? window.webkitAudioContext;

    if (!AudioContextClass) {
        return null;
    }

    if (userChannelState.audio === null) {
        userChannelState.audio = new AudioContextClass();
        userChannelState.audio.addEventListener('statechange', () => {
            if (userChannelState.audio?.state === 'running') {
                handleAudioReady();
            }
        });
    }

    if (userChannelState.audio.state === 'suspended') {
        userChannelState.audio.resume().catch(() => {});
    }

    return userChannelState.audio;
}

/**
 * O contexto passou a rodar: o som está liberado para o resto da página.
 *
 * Desarma os gestos (o áudio não volta a ser bloqueado), pré-carrega o MP3
 * configurado e toca imediatamente o alarme que estava esperando a interação —
 * sem isso o atendente só ouviria o toque no próximo ciclo de repetição.
 */
function handleAudioReady() {
    releaseAudioUnlock();
    updateAudioHint();
    channelLog('audio.liberado', { estado: userChannelState.audio?.state ?? null, alarmeAtivo: userChannelState.alertActive });

    if (userChannelState.sound === CUSTOM_REQUEST_SOUND && userChannelState.soundUrl) {
        void loadCustomSound(userChannelState.soundUrl);
    }

    if (userChannelState.alertActive && Date.now() - userChannelState.alertLastPlayAt > 800) {
        playRequestSound(userChannelState.sound);
    }
}

function onAudioGesture() {
    const context = primeAudio();

    if (!context) {
        releaseAudioUnlock();

        return;
    }

    if (context.state === 'running') {
        handleAudioReady();
    }
}

function releaseAudioUnlock() {
    userChannelState.audioUnlockListeners.forEach(([type, listener]) => {
        document.removeEventListener(type, listener);
    });
    userChannelState.audioUnlockListeners = [];
}

/**
 * Escuta os primeiros gestos da página até o áudio ser liberado.
 *
 * Os listeners não usam `once`: se o primeiro toque não for suficiente para o
 * navegador liberar o som, o próximo tenta de novo. Eles só saem de cena em
 * `releaseAudioUnlock`, quando o contexto já está em `running`.
 */
function bindAudioUnlock() {
    if (userChannelState.audioUnlockBound) {
        return;
    }

    userChannelState.audioUnlockBound = true;

    ['pointerdown', 'keydown', 'touchstart'].forEach((type) => {
        document.addEventListener(type, onAudioGesture, { passive: true });
        userChannelState.audioUnlockListeners.push([type, onAudioGesture]);
    });
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
        /*
         * Este é o único motivo pelo qual a sirene "não toca": o navegador ainda
         * não liberou o áudio (nenhum gesto aconteceu). O log deixa isso
         * explícito em produção em vez de parecer um alerta perdido — e sai uma
         * única vez por alerta, não a cada repetição do toque.
         */
        if (!userChannelState.alertBlockedLogged) {
            userChannelState.alertBlockedLogged = true;
            channelLog('sirene.bloqueada', { estado: context?.state ?? 'sem-contexto' });
        }

        return;
    }

    const sound = normalizeRequestSound(value);

    userChannelState.alertLastPlayAt = Date.now();
    channelLog('sirene.toque', { som: sound, contexto: context.state });

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

    channelLog('sirene.inicio', {
        som: userChannelState.sound,
        contexto: userChannelState.audio?.state ?? 'sem-contexto',
        solicitacoes: [...userChannelState.requests.keys()],
    });

    userChannelState.alertActive = true;
    userChannelState.alertBlockedLogged = false;
    userChannelState.alertLastPlayAt = 0;

    // Garante que o próximo clique/tecla da página libere o som e toque o
    // alarme na hora, mesmo que o atendente ainda não tenha interagido.
    bindAudioUnlock();
    updateAudioHint();

    playRequestSound(userChannelState.sound);
    scheduleNextAlert();

    userChannelState.alertStopTimer = window.setTimeout(stopAlert, ALERT_MAX_MS);
}

function stopAlert() {
    window.clearTimeout(userChannelState.alertTimer);
    window.clearTimeout(userChannelState.alertStopTimer);
    userChannelState.alertTimer = null;
    userChannelState.alertStopTimer = null;
    userChannelState.alertActive = false;
    userChannelState.alertLastPlayAt = 0;
    userChannelState.alertBlockedLogged = false;
    updateAudioHint();

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
    const existed = userChannelState.requests.delete(key);

    requestsList()?.querySelector(`[data-request-id="${key}"]`)?.remove();

    if (existed) {
        channelLog('fila.remove', { id: key, restantes: [...userChannelState.requests.keys()] });
    }

    if (userChannelState.requests.size === 0) {
        requestsList()?.classList.add('d-none');
        document.querySelector('[data-requests-empty]')?.classList.remove('d-none');
        stopAlert();
    }

    updatePendingCount();

    if (userChannelState.currentId === key) {
        userChannelState.currentId = null;

        /*
         * Com uma decisão em curso o modal fica onde está: `decide()` já trata
         * a resposta (recarrega, avança para o próximo pedido ou navega). Mexer
         * aqui fecharia o modal no meio do `PATCH`.
         */
        if (userChannelState.deciding) {
            channelLog('modal.mantido', { motivo: 'decisao-em-andamento', id: key });

            return;
        }

        /*
         * O pedido exibido saiu da fila (respondido em outra aba, cancelado
         * pelo cliente…). Se ainda há outros, o modal passa a mostrar o
         * próximo; mantê-lo com o conteúdo antigo deixaria os botões ligados a
         * um `currentId` vazio, e o clique não responderia nada.
         */
        openNextRequest();
    }
}

/**
 * Tira da fila o pedido de uma consulta que **deixou** de estar pendente.
 *
 * O feed do painel devolve em `consultation` a consulta aberta do usuário, e
 * `requested` conta como aberta: a mesma solicitação aparece na fila
 * (`requests`) **e** em `consultation`. Remover a fila por causa dessa leitura
 * apagava o pedido que o próprio atendente tinha na tela — e o poll seguinte o
 * reinseria como "novo", reabrindo o modal e a sirene em laço, com os botões
 * ligados a nenhum pedido. A fila é a fonte de verdade do que está pendente;
 * `consultation` só serve aqui para detectar que o pedido foi respondido.
 */
function forgetAnsweredRequest(consultation) {
    const requestId = Number(consultation?.id);

    if (!requestId || consultation?.status === 'requested') {
        return;
    }

    if (userChannelState.requests.has(requestId)) {
        channelLog('fila.respondido', { id: requestId, status: consultation.status });
        removeRequest(requestId);
    }
}


/**
 * Devolve o foco para fora do modal antes de o Bootstrap escondê-lo.
 *
 * O Bootstrap não restaura o foco ao fechar: o elemento focado continua dentro
 * do modal quando `aria-hidden="true"` é aplicado. O navegador então bloqueia o
 * atributo (“Blocked aria-hidden on an element because its descendant retained
 * focus”) e o teclado fica preso numa região invisível. Rodamos no evento
 * `hide.bs.modal`, que acontece antes de o atributo ser aplicado.
 */
function restoreRequestModalFocus() {
    const element = requestModal();
    const active = document.activeElement;

    if (!element || !active || !element.contains(active)) {
        return;
    }

    const previous = userChannelState.modalFocusReturn;
    userChannelState.modalFocusReturn = null;

    if (previous?.isConnected && previous !== document.body && typeof previous.focus === 'function') {
        previous.focus({ preventScroll: true });

        return;
    }

    const fallback = document.querySelector('#conteudo-principal, main');

    if (fallback) {
        if (!fallback.hasAttribute('tabindex')) {
            fallback.setAttribute('tabindex', '-1');
        }

        fallback.focus({ preventScroll: true });

        return;
    }

    active.blur();
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

    // Memoriza de onde o foco veio só na abertura: trocar o conteúdo com o
    // modal já aberto não deve sobrescrever o destino de retorno.
    if (!element.classList.contains('show')) {
        userChannelState.modalFocusReturn = document.activeElement;
        channelLog('modal.abrir', { id: Number(request.id) });
    }

    userChannelState.modal = Modal.getOrCreateInstance(element);
    userChannelState.modal.show();
}

function openRequestModal(requestId) {
    const request = userChannelState.requests.get(Number(requestId));

    if (!request) {
        // Só acontece se o pedido saiu da fila entre a leitura e a abertura.
        channelLog('modal.ignorado', { motivo: 'pedido-fora-da-fila', id: Number(requestId) });

        return;
    }

    userChannelState.currentId = Number(requestId);
    fillRequestModal(request);
}

/**
 * Fecha o modal de solicitações, registrando o motivo no log de diagnóstico.
 */
function hideRequestModal(reason) {
    const element = requestModal();

    if (!element) {
        return;
    }

    channelLog('modal.fechar', { motivo: reason, id: userChannelState.currentId });

    userChannelState.modal ??= Modal.getOrCreateInstance(element);
    userChannelState.modal.hide();
}

/**
 * Abre o próximo pedido da fila.
 *
 * Quando o modal já está aberto o conteúdo é trocado no lugar: fechar e abrir
 * em seguida faria o Bootstrap ignorar a segunda abertura (`_isTransitioning`).
 * Com uma decisão em curso nada muda — quem fecha ou navega é `decide()`.
 */
function openNextRequest() {
    if (userChannelState.deciding) {
        channelLog('modal.mantido', { motivo: 'decisao-em-andamento', etapa: 'proximo-pedido' });

        return;
    }

    const next = [...userChannelState.requests.keys()][0];

    if (next === undefined) {
        userChannelState.currentId = null;
        hideRequestModal('fila-vazia');

        return;
    }

    openRequestModal(next);
}

/**
 * Responde (aceitar ou recusar) o pedido exibido no modal.
 *
 * Toda a decisão é do servidor: aqui só se envia `PATCH` para a rota indicada
 * pelo cartão da solicitação e se traduz a resposta. O envio usa o caminho
 * relativo (`sameOriginUrl`) para não depender do esquema/host que o Laravel
 * gerou — atrás de proxy com TLS isso evitaria um `fetch` bloqueado como mixed
 * content — e tem tempo limite, para os botões nunca ficarem travados em uma
 * conexão pendurada.
 */
async function decide(action) {
    const element = requestModal();

    if (!element || userChannelState.deciding) {
        channelLog('decisao.ignorada', { acao: action, motivo: element ? 'decisao-em-andamento' : 'sem-modal' });

        return;
    }

    const request = userChannelState.requests.get(userChannelState.currentId);
    const url = sameOriginUrl(action === 'accept' ? request?.accept_url : request?.reject_url);

    channelLog('decisao', {
        acao: action,
        id: userChannelState.currentId,
        fila: [...userChannelState.requests.keys()],
        url,
    });

    /*
     * `currentId` vazio significa que a solicitação saiu da fila entre a
     * abertura do modal e o clique (outra aba respondeu, o cliente cancelou…).
     * Sem esta mensagem o clique parecia não funcionar.
     */
    if (!request || !url) {
        channelLog('decisao.sem-pedido', { acao: action, id: userChannelState.currentId, url });

        setRequestError('Esta solicitação não está mais disponível. Atualizando a fila…');
        openNextRequest();
        syncPending();

        return;
    }

    const buttons = element.querySelectorAll('[data-request-accept], [data-request-reject]');
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), DECISION_TIMEOUT_MS);

    userChannelState.deciding = true;
    buttons.forEach((button) => {
        button.disabled = true;
    });
    setRequestError('');

    try {
        const response = await fetch(url, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: requestHeaders(),
            signal: controller.signal,
        });

        const payload = await response.json().catch(() => ({}));

        channelLog('decisao.resposta', {
            acao: action,
            id: request.id,
            status: response.status,
            mensagem: payload?.message ?? null,
            redirect: payload?.redirect ?? null,
        });

        /*
         * 419: o token CSRF da página não vale mais (sessão expirada ou cookie
         * refeito). Recarregar recupera token e fila; sem isso o painel ficava
         * aberto respondendo "não foi possível" para sempre.
         */
        if (response.status === 419) {
            reloadAfterSessionExpiry();

            return;
        }

        /*
         * 403/409/410 significam que outra aba, outro atendente ou o cliente
         * chegaram primeiro. O pedido sai da fila e a lista é ressincronizada
         * com o servidor, que é a fonte de verdade.
         */
        if (response.status === 403 || response.status === 409 || response.status === 410) {
            setRequestError(payload.message ?? 'Esta solicitação não está mais disponível.');

            // Libera a decisão antes de mexer na fila: `removeRequest` abre o
            // próximo pedido, e `openNextRequest` não age durante uma decisão.
            finishDecision();
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
            setRequestError(payload.message ?? `Falha ao responder a solicitação (${response.status}). Tente novamente.`);

            return;
        }

        stopAlert();
        finishDecision();
        removeRequest(request.id);

        if (action === 'accept' && payload.redirect) {
            leaveTo(payload.redirect);

            return;
        }

        setUserNotice(payload.message ?? 'Solicitação recusada.');
        window.setTimeout(() => setUserNotice(''), 6000);
    } catch (error) {
        channelLog('decisao.erro', { acao: action, nome: error?.name ?? 'Error', mensagem: error?.message ?? String(error) });

        setRequestError(
            error?.name === 'AbortError'
                ? 'O servidor demorou para responder. Verifique a conexão e tente novamente.'
                : 'Não foi possível responder agora. Verifique a conexão e tente novamente.',
        );
    } finally {
        window.clearTimeout(timeout);
        finishDecision();
    }
}

/**
 * Encerra o estado "decidindo": libera os botões e permite que a fila avance.
 *
 * Chamado também antes de `removeRequest`, porque é ele quem abre o próximo
 * pedido — e `openNextRequest` não troca o conteúdo do modal enquanto houver
 * uma decisão em andamento.
 */
function finishDecision() {
    userChannelState.deciding = false;

    requestModal()
        ?.querySelectorAll('[data-request-accept], [data-request-reject]')
        .forEach((button) => {
            button.disabled = false;
        });
}

/**
 * Recarrega o painel uma vez quando o servidor recusa o token CSRF.
 *
 * A recarga devolve um token novo e a fila atual; a guarda evita laço caso a
 * sessão já tenha sido encerrada de vez (aí o próximo carregamento leva ao
 * login, não a este código).
 */
function reloadAfterSessionExpiry() {
    setRequestError('Sua sessão expirou. Recarregando o painel…');

    if (userChannelState.reloadingForSession) {
        return;
    }

    userChannelState.reloadingForSession = true;
    window.setTimeout(() => window.location.reload(), 1200);
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
    const url = sameOriginUrl(root?.dataset.eventsUrl || root?.dataset.pendingUrl);

    if (!url || userChannelState.syncing) {
        return 'skipped';
    }

    userChannelState.syncing = true;

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: requestHeaders(),
        });

        if (response.status === 419) {
            // Token CSRF expirado: o painel inteiro para de atualizar até
            // recarregar. A recarga é única por carregamento (guarda em
            // `reloadAfterSessionExpiry`), então não há laço.
            reloadAfterSessionExpiry();

            return 'error';
        }

        if (!response.ok) {
            throw new Error(`Falha ao listar solicitações (${response.status}).`);
        }

        const payload = await response.json();

        logSyncResult(payload);

        applyRequests(payload.requests ?? [], { notifyWhenNew });

        if (payload.consultation) {
            applyConsultationState(payload.consultation);
        } else {
            syncConsultationReturn(null);
            handleClosedConsultation();
        }

        return 'ok';
    } catch (error) {
        channelLog('sync.erro', { url, mensagem: error?.message ?? String(error) });

        // Silencioso para o usuário: a próxima leitura tenta de novo com espera
        // maior (o agendador faz o backoff).
        return 'error';
    } finally {
        userChannelState.syncing = false;
    }
}

/*
 * Log de uma leitura do feed, emitido só quando algo muda.
 *
 * O poll roda a cada 3 s; repetir a mesma foto encheria o console e esconderia
 * o que importa. `consultation` é registrada sempre que muda de estado, o que
 * mostra a transição `requested -> active` no aceite.
 */
function logSyncResult(payload) {
    const ids = (payload.requests ?? []).map((request) => Number(request.id));
    const consultation = payload.consultation;
    const signature = [
        ids.join(','),
        consultation ? `${consultation.id}:${consultation.status}` : 'sem-consulta',
    ].join('|');

    if (signature === userChannelState.lastQueueSignature) {
        return;
    }

    userChannelState.lastQueueSignature = signature;
    channelLog('sync', {
        solicitacoes: ids,
        consulta: consultation ? { id: consultation.id, status: consultation.status } : null,
        emTela: userChannelState.currentId,
        modalAberto: requestModal()?.classList.contains('show') ?? false,
    });
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

    if (!notifyWhenNew || firstNew === null || requestModal() === null) {
        return;
    }

    startAlert();

    /*
     * Um pedido novo não rouba o modal de quem já está decidindo outro: o
     * atendente veria o conteúdo trocar no meio do clique. Sem pedido em tela,
     * abre o primeiro da fila (é o caso da carga inicial e da reconexão).
     */
    if (userChannelState.currentId !== null) {
        channelLog('modal.mantido', { motivo: 'ja-exibe-outro-pedido', atual: userChannelState.currentId, novo: firstNew.id });

        return;
    }

    openRequestModal(firstNew.id);
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

    syncConsultationReturn(consultation);

    // Só sai da fila quando a consulta deixou de estar pendente: `requested`
    // aqui é a mesma solicitação que está em `requests` (ver
    // `forgetAnsweredRequest`).
    forgetAnsweredRequest(consultation);

    if (userChannelState.lastState === null) {
        userChannelState.lastState = signature;
        userChannelState.lastConsultationId = requestId;

        return;
    }

    if (signature === userChannelState.lastState) {
        return;
    }

    userChannelState.lastState = signature;
    userChannelState.lastConsultationId = requestId;
    handleState({ consultation });
}

/*
 * A leitura do painel não devolve mais consulta aberta.
 *
 * Se esta página está exibindo exatamente a consulta que estava aberta na
 * leitura anterior, ela acabou de chegar a um estado final — recusada,
 * cancelada, expirada, encerrada ou interrompida — e precisa mostrar isso em vez
 * de continuar exibindo "aguardando aceite".
 *
 * A comparação com `data-consultation-watch` evita recarregar telas que não
 * mostram aquela consulta (painel, perfil, carteira) e evita laço: a página do
 * estado final não tem leitura anterior para comparar.
 */
function handleClosedConsultation() {
    const watchedElement = document.querySelector('[data-consultation-watch]');

    if (!watchedElement || userChannelState.leaving) {
        return;
    }

    const watched = Number(watchedElement.dataset.consultationWatch);

    if (!Number.isFinite(watched) || watched !== userChannelState.lastConsultationId) {
        return;
    }

    userChannelState.lastState = null;
    userChannelState.lastConsultationId = null;
    window.location.reload();
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
    } else {
        channelLog('modal.mantido', { motivo: 'ja-exibe-outro-pedido', atual: userChannelState.currentId, novo: request.id });
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
    userChannelState.lastConsultationId = requestId;

    syncConsultationReturn(consultation);

    forgetAnsweredRequest(consultation);

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
        channelLog('modal.fechado', { id: userChannelState.currentId, fila: [...userChannelState.requests.keys()] });

        userChannelState.currentId = null;
        stopAlert();
    });

    /*
     * Rede de segurança do fechamento.
     *
     * O Bootstrap ignora `hide()` durante a transição de abertura
     * (`_isTransitioning`). Se o pedido sair da fila nesse intervalo, o modal
     * terminava de abrir sem nenhum pedido em tela — botões visíveis e sem
     * efeito. Ao concluir a abertura sem `currentId`, fecha de novo.
     */
    modal?.addEventListener('shown.bs.modal', () => {
        if (userChannelState.currentId === null) {
            channelLog('modal.reaberto-sem-pedido', { acao: 'fechando' });
            hideRequestModal('abriu-sem-pedido');
        }
    });

    // Tira o foco de dentro do modal antes de o Bootstrap aplicar
    // `aria-hidden="true"` no fechamento (ver `restoreRequestModalFocus`).
    modal?.addEventListener('hide.bs.modal', restoreRequestModalFocus);

    // Navegadores exigem interação do usuário antes de liberar áudio; só o
    // atendente (que tem modal) precisa da sirene. O contexto nasce no primeiro
    // gesto (nunca no carregamento) e o alarme pendente toca na hora.
    if (modal) {
        bindAudioUnlock();
    }

    const mode = root.dataset.realtimeMode === 'poll' ? 'poll' : 'socket';

    /*
     * Primeira linha do diagnóstico: sem ela não se sabe, no servidor do
     * cliente, qual build está carregado nem em que modo o painel está. É a
     * checagem que separa "correção não subiu" de "correção não funcionou".
     */
    channelLog('iniciado', {
        build: loadedBuildName(),
        modo: mode,
        usuario: userChannelState.userId,
        atendente: modal !== null,
        som: userChannelState.sound,
        arquivoSom: userChannelState.soundUrl,
        urlEventos: root.dataset.eventsUrl ?? null,
        urlPendentes: root.dataset.pendingUrl ?? null,
    });

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
        .listen('.consultation.request', (event) => {
            channelLog('evento.request', {
                id: Number(event?.request?.id),
                novo: !userChannelState.requests.has(Number(event?.request?.id)),
                podeResponder: requestModal() !== null,
            });

            handleRequest(event);
        })
        .listen('.consultation.state', (event) => {
            channelLog('evento.state', {
                id: Number(event?.consultation?.id),
                status: event?.consultation?.status ?? null,
            });

            handleState(event);
        })
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

