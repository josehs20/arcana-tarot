import { Modal } from 'bootstrap';
import { autoplayAllowed, markAutoplayAllowed } from './audio-autoplay';
import { createPoller, readMilliseconds } from './polling';

import { markIntentionalNavigation } from './navigation-intent';

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

/*
 * Áudio das mensagens.
 *
 * Mesmo problema da sirene do atendente: o navegador só libera som depois de
 * uma interação e volta a suspender um `AudioContext` ocioso. A retomada é
 * tentada com espera crescente, na volta do foco e a cada mensagem recebida;
 * enquanto o contexto está ativo, um buffer silencioso a cada 20 s impede que
 * ele seja suspenso de novo.
 */
const CHAT_AUDIO_RETRY_MS = [600, 2000, 6000, 15000];
const CHAT_AUDIO_KEEPALIVE_MS = 20000;
const CHAT_AUDIO_UNLOCK_KEY = 'arcana:audio-unlocked';

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
    pendingMessages: 0,
    sendOnEnter: true,
    soundEnabled: true,
    soundArmed: false,
    audio: null,
    audioRetryTimer: null,
    audioRetryAttempt: 0,
    audioKeepAliveTimer: null,
    audioWatchBound: false,
    pendingSound: false,
    emojiGlobalBound: false,
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
        return null;
    }

    chatState.audio ??= new AudioContextClass();

    if (chatState.audio.state === 'suspended') {
        chatState.audio.resume().catch(() => {});
    }

    if (chatState.audio.state === 'running') {
        rememberChatAudioUnlock();
        startChatAudioKeepAlive();
        cancelChatAudioRetry();
    }

    return chatState.audio;
}

/**
 * Retoma o áudio para tocar a mensagem sem exigir clique.
 *
 * O `AudioContext` some para `suspended` quando o navegador decide que a página
 * está ociosa — e a aba do chat passa horas aberta. Aqui a retomada é tentada
 * em três ocasiões: no carregamento (quando este navegador já liberou o som
 * antes), ao voltar o foco e quando uma mensagem nova chega.
 */
function attemptChatAudio() {
    if (!chatState.soundEnabled) {
        return null;
    }

    const context = primeChatAudio();

    if (context && context.state !== 'running') {
        scheduleChatAudioRetry();
    }

    return context;
}

/**
 * Tenta liberar o som sem gesto, só quando o navegador permite.
 *
 * A permissão é sondada por um `<audio>` silencioso (`audio-autoplay.js`), que
 * não gera aviso no console. Quando o navegador bloqueia, nada é criado: o
 * áudio fica esperando o primeiro clique, como antes.
 */
function attemptChatAutoplay() {
    autoplayAllowed().then((allowed) => {
        if (!allowed) {
            return;
        }

        markAutoplayAllowed();
        primeChatAudio();
    });
}

function scheduleChatAudioRetry() {
    if (chatState.audioRetryTimer !== null || !chatState.audio || chatState.audio.state === 'running') {
        return;
    }

    const delay = CHAT_AUDIO_RETRY_MS[Math.min(chatState.audioRetryAttempt, CHAT_AUDIO_RETRY_MS.length - 1)];

    chatState.audioRetryAttempt += 1;
    chatState.audioRetryTimer = window.setTimeout(() => {
        chatState.audioRetryTimer = null;
        primeChatAudio();

        if (chatState.audio?.state !== 'running' && chatState.audioRetryAttempt < CHAT_AUDIO_RETRY_MS.length) {
            scheduleChatAudioRetry();
        }
    }, delay);
}

function cancelChatAudioRetry() {
    window.clearTimeout(chatState.audioRetryTimer);
    chatState.audioRetryTimer = null;
    chatState.audioRetryAttempt = 0;
}

/**
 * Silêncio periódico: mantém o contexto em `running` mesmo sem mensagens.
 */
function startChatAudioKeepAlive() {
    if (chatState.audioKeepAliveTimer !== null) {
        return;
    }

    chatState.audioKeepAliveTimer = window.setInterval(() => {
        const context = chatState.audio;

        if (!context) {
            return;
        }

        if (context.state !== 'running') {
            primeChatAudio();

            return;
        }

        try {
            const silence = context.createBuffer(1, 1, context.sampleRate);
            const source = context.createBufferSource();
            source.buffer = silence;
            source.connect(context.destination);
            source.start(0);
        } catch {
            // Contexto fechado pelo navegador: a próxima tentativa recria.
        }
    }, CHAT_AUDIO_KEEPALIVE_MS);
}

function chatAudioUnlockedBefore() {
    try {
        return window.localStorage?.getItem(CHAT_AUDIO_UNLOCK_KEY) === '1';
    } catch {
        return false;
    }
}

function rememberChatAudioUnlock() {
    try {
        window.localStorage?.setItem(CHAT_AUDIO_UNLOCK_KEY, '1');
    } catch {
        // Modo privado/navegador sem storage: o desbloqueio vale só nesta página.
    }
}

/**
 * Retoma um contexto que já existe, sem criar um novo.
 *
 * Usado nos eventos de foco/visibilidade: criar `AudioContext` fora de um gesto
 * só produz o aviso "The AudioContext was not allowed to start" no console, sem
 * nenhum ganho — o navegador mantém o contexto suspenso de qualquer forma.
 */
function resumeChatAudio() {
    if (!chatState.audio || !chatState.soundEnabled) {
        return;
    }

    primeChatAudio();
}

function bindChatAudioWatch() {
    if (chatState.audioWatchBound) {
        return;
    }

    chatState.audioWatchBound = true;

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            resumeChatAudio();
        }
    });
    window.addEventListener('focus', resumeChatAudio);
    window.addEventListener('pageshow', resumeChatAudio);
}

function playIncomingSound() {
    if (!chatState.soundEnabled || !chatState.soundArmed) {
        return;
    }

    const context = chatState.audio;

    if (!context) {
        return;
    }

    if (context.state !== 'running') {
        /*
         * Contexto ainda retomando (acontece na primeira mensagem depois de a
         * aba ficar ociosa): o tom entra assim que ele passar a rodar, em vez
         * de a mensagem chegar em silêncio. O `once` evita pilha de listeners;
         * `chatState.pendingSound` registra que já existe um esperando.
         */
        if (chatState.pendingSound) {
            return;
        }

        chatState.pendingSound = true;
        context.addEventListener('statechange', function playWhenReady() {
            if (context.state !== 'running') {
                return;
            }

            context.removeEventListener('statechange', playWhenReady);
            chatState.pendingSound = false;
            playIncomingSound();
        });

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

    if (!chatState.soundEnabled || !chatState.soundArmed) {
        return;
    }

    /*
     * Primeira mensagem sem nenhum gesto na página: o contexto ainda não
     * existe. A permissão é sondada antes de criá-lo e o toque sai assim que ele
     * estiver ativo — sem isso a mensagem chegaria em silêncio justamente em
     * quem acabou de abrir a aba.
     */
    if (!chatState.audio) {
        autoplayAllowed().then((allowed) => {
            if (!allowed) {
                return;
            }

            markAutoplayAllowed();
            primeChatAudio();
            playIncomingSound();
        });

        return;
    }

    attemptChatAudio();
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

/*
 * Rolagem da conversa.
 *
 * A linha do tempo fica dentro de `[data-chat-stream]`, que tem altura limitada
 * e rolagem própria. Acompanhar o fim só faz sentido quando o participante já
 * está lá: quem subiu para reler o histórico não deve ser jogado para baixo a
 * cada mensagem nova. Nesse caso o contador `data-chat-jump` sinaliza o que
 * chegou e devolve a rolagem ao fim em um clique.
 */
function chatStream(chat) {
    return chat?.querySelector('[data-chat-stream]') ?? null;
}

function isNearStreamBottom(stream) {
    if (!stream) {
        return true;
    }

    return stream.scrollHeight - stream.scrollTop - stream.clientHeight <= 140;
}

function scrollStreamToBottom(stream, behavior = 'smooth') {
    if (!stream) {
        return;
    }

    stream.scrollTo({ top: stream.scrollHeight, behavior });
}

function setJumpVisible(chat, visible) {
    chat?.querySelector('[data-chat-jump]')?.classList.toggle('d-none', !visible);

    if (!visible) {
        chatState.pendingMessages = 0;
    }

    const label = chat?.querySelector('[data-chat-jump-label]');

    if (!label) {
        return;
    }

    label.textContent =
        chatState.pendingMessages > 1
            ? `${chatState.pendingMessages} novas mensagens`
            : 'Nova mensagem';
}

function registerPendingMessage(chat) {
    chatState.pendingMessages += 1;
    setJumpVisible(chat, true);
}

function bindChatStream(chat) {
    const stream = chatStream(chat);
    const jump = chat?.querySelector('[data-chat-jump]');

    if (!stream || stream.dataset.chatStreamBound === '1') {
        return;
    }

    stream.dataset.chatStreamBound = '1';

    stream.addEventListener('scroll', () => {
        if (isNearStreamBottom(stream)) {
            setJumpVisible(chat, false);
        }
    });

    jump?.addEventListener('click', () => {
        scrollStreamToBottom(stream);
        setJumpVisible(chat, false);
        markChatRead(chat);
    });

    // Abrir a conversa mostra sempre a mensagem mais recente.
    scrollStreamToBottom(stream, 'auto');
}

function createTextElement(tag, className, text) {
    const element = document.createElement(tag);

    if (className) {
        element.className = className;
    }

    element.textContent = text ?? '';

    return element;
}

function createCardFigure(message) {
    const figure = document.createElement('figure');
    figure.className = 'chat-card mb-0';
    figure.dataset.messageId = String(message.id);

    if (message.image_url) {
        const link = document.createElement('a');
        link.href = message.image_url;
        link.target = '_blank';
        link.rel = 'noopener';

        const image = document.createElement('img');
        // O balão carrega a miniatura gerada no servidor; a imagem cheia fica no
        // link, aberta em nova aba.
        image.src = message.image_thumb_url ?? message.image_url;
        image.alt = `Carta ${message.card_name ?? ''}`.trim();
        image.loading = 'lazy';
        image.decoding = 'async';

        link.append(image);
        figure.append(link);
    }

    const caption = document.createElement('figcaption');
    caption.append(
        createTextElement('strong', '', message.card_name ?? 'Carta'),
        createTextElement('span', '', message.deck_name ?? ''),
    );

    figure.append(caption);

    return figure;
}

/*
 * Mensagens de voz do atendimento por áudio.
 *
 * A gravação acontece no navegador (`MediaRecorder`) e só sobe ao servidor
 * depois da confirmação do participante: descartar a prévia não gera upload.
 * O binário vira o campo `audio` de um envio multipart junto com a duração e o
 * mime detectados aqui — o servidor revalida formato, tamanho e duração e é
 * quem decide o caminho e a extensão do arquivo.
 */
const VOICE_MIME_CANDIDATES = [
    { mime: 'audio/webm;codecs=opus', extension: 'webm' },
    { mime: 'audio/webm', extension: 'webm' },
    { mime: 'audio/ogg;codecs=opus', extension: 'ogg' },
    { mime: 'audio/mp4', extension: 'm4a' },
];

const voiceState = {
    recorder: null,
    stream: null,
    chunks: [],
    blob: null,
    mimeType: '',
    seconds: 0,
    startedAt: 0,
    ticker: null,
    previewUrl: null,
    abandoned: false,
};

function voiceSupported() {
    return (
        typeof MediaRecorder !== 'undefined'
        && typeof navigator !== 'undefined'
        && typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

/*
 * Safari grava em mp4, Chrome/Firefox em webm ou ogg. O primeiro formato
 * suportado é usado; sem nenhum, o navegador escolhe e a extensão é derivada
 * do mime gravado.
 */
function voicePreferredFormat() {
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
        return null;
    }

    return VOICE_MIME_CANDIDATES.find((format) => MediaRecorder.isTypeSupported(format.mime)) ?? null;
}

function voiceExtensionFor(mimeType) {
    const normalized = String(mimeType ?? '').toLowerCase();
    const match = VOICE_MIME_CANDIDATES.find((format) => normalized.startsWith(format.mime.split(';')[0]));

    return match?.extension ?? 'webm';
}

function formatVoiceSeconds(total) {
    const safe = Math.max(0, Math.floor(total));

    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

function voiceFields(form) {
    return {
        toggle: form.querySelector('[data-chat-voice-toggle]'),
        toggleLabel: form.querySelector('[data-chat-voice-toggle-label]'),
        discard: form.querySelector('[data-chat-voice-discard]'),
        send: form.querySelector('[data-chat-voice-send]'),
        preview: form.querySelector('[data-chat-voice-preview]'),
        status: form.querySelector('[data-chat-voice-status]'),
        duration: form.querySelector('[data-chat-voice-duration]'),
        mime: form.querySelector('[data-chat-voice-mime]'),
    };
}

function voiceMaxSeconds(form) {
    return Number(form?.dataset.maxSeconds ?? 120) || 120;
}

function setVoiceStatus(form, message) {
    const { status } = voiceFields(form);

    if (status) {
        status.textContent = message ?? '';
    }
}

function hideVoiceElement(element) {
    element?.classList.add('d-none');
}

function showVoiceElement(element) {
    element?.classList.remove('d-none');
}

function updateVoiceUi(form) {
    const fields = voiceFields(form);
    const recording = voiceState.recorder !== null;
    const recorded = voiceState.blob !== null;

    if (fields.toggleLabel) {
        fields.toggleLabel.textContent = recording ? 'Parar gravação' : recorded ? 'Gravar novamente' : 'Gravar áudio';
    }

    fields.toggle?.classList.toggle('is-recording', recording);
    fields.toggle?.setAttribute('aria-pressed', recording ? 'true' : 'false');

    if (fields.duration) {
        fields.duration.value = recorded ? String(voiceState.seconds) : '';
    }

    if (fields.mime) {
        fields.mime.value = recorded ? voiceState.mimeType : '';
    }

    if (recording) {
        hideVoiceElement(fields.send);
        hideVoiceElement(fields.preview);

        return;
    }

    if (recorded) {
        showVoiceElement(fields.send);
        showVoiceElement(fields.discard);
        showVoiceElement(fields.preview);

        return;
    }

    hideVoiceElement(fields.send);
    hideVoiceElement(fields.discard);
    hideVoiceElement(fields.preview);
}

function stopVoiceTicker() {
    if (voiceState.ticker !== null) {
        window.clearInterval(voiceState.ticker);
        voiceState.ticker = null;
    }
}

function startVoiceTicker(form) {
    stopVoiceTicker();

    voiceState.ticker = window.setInterval(() => {
        voiceState.seconds = Math.round((Date.now() - voiceState.startedAt) / 1000);

        if (voiceState.seconds >= voiceMaxSeconds(form)) {
            setVoiceStatus(form, `Limite de ${formatVoiceSeconds(voiceMaxSeconds(form))} atingido. Envie ou descarte a gravação.`);
            stopVoiceRecording(form);

            return;
        }

        setVoiceStatus(
            form,
            `Gravando… ${formatVoiceSeconds(voiceState.seconds)} de ${formatVoiceSeconds(voiceMaxSeconds(form))}. Toque em "Parar gravação" para revisar.`,
        );
    }, 500);
}

function releaseVoiceStream() {
    voiceState.stream?.getTracks().forEach((track) => track.stop());
    voiceState.stream = null;
}

function setVoicePreview(form, blob) {
    const { preview } = voiceFields(form);

    if (voiceState.previewUrl) {
        URL.revokeObjectURL(voiceState.previewUrl);
        voiceState.previewUrl = null;
    }

    if (!preview) {
        return;
    }

    if (!blob) {
        preview.removeAttribute('src');
        preview.load();

        return;
    }

    voiceState.previewUrl = URL.createObjectURL(blob);
    preview.src = voiceState.previewUrl;
}

function clearVoiceCapture(form) {
    stopVoiceTicker();
    releaseVoiceStream();

    voiceState.recorder = null;
    voiceState.chunks = [];
    voiceState.blob = null;
    voiceState.mimeType = '';
    voiceState.seconds = 0;
    voiceState.startedAt = 0;

    setVoicePreview(form, null);

    if (form) {
        updateVoiceUi(form);
    }
}

function finalizeVoiceRecording(form) {
    stopVoiceTicker();
    releaseVoiceStream();

    if (voiceState.abandoned) {
        voiceState.abandoned = false;
        voiceState.recorder = null;
        voiceState.chunks = [];
        voiceState.blob = null;
        voiceState.mimeType = '';
        voiceState.seconds = 0;

        return;
    }

    const mimeType = voiceState.mimeType;

    voiceState.recorder = null;

    const blob = new Blob(voiceState.chunks, { type: mimeType || 'audio/webm' });
    voiceState.chunks = [];
    voiceState.seconds = Math.max(1, Math.round((Date.now() - voiceState.startedAt) / 1000));

    if (blob.size === 0) {
        voiceState.blob = null;
        setVoiceStatus(form, 'A gravação ficou vazia. Tente novamente.');
        updateVoiceUi(form);

        return;
    }

    voiceState.blob = blob;
    setVoicePreview(form, blob);
    setVoiceStatus(
        form,
        `Gravação pronta (${formatVoiceSeconds(voiceState.seconds)}). Ouça antes de enviar ou descarte para gravar de novo.`,
    );
    updateVoiceUi(form);
}

async function startVoiceRecording(form) {
    if (voiceState.recorder) {
        return;
    }

    if (!voiceSupported()) {
        setVoiceStatus(form, 'Este navegador não permite gravar áudio.');
        setFormError(form, 'Grave o áudio em outro navegador ou envie a mensagem por texto.');

        return;
    }

    setFormError(form, '');
    setVoiceStatus(form, 'Pedindo permissão para o microfone…');

    try {
        voiceState.stream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
    } catch {
        setVoiceStatus(form, 'Sem acesso ao microfone.');
        setFormError(form, 'Não foi possível acessar o microfone. Autorize o uso no navegador e tente novamente.');

        return;
    }

    const format = voicePreferredFormat();
    let recorder;

    try {
        recorder = format
            ? new MediaRecorder(voiceState.stream, { mimeType: format.mime })
            : new MediaRecorder(voiceState.stream);
    } catch {
        releaseVoiceStream();
        setVoiceStatus(form, 'Não foi possível iniciar a gravação.');
        setFormError(form, 'Não foi possível iniciar a gravação neste navegador.');

        return;
    }

    voiceState.recorder = recorder;
    voiceState.chunks = [];
    voiceState.blob = null;
    voiceState.mimeType = recorder.mimeType || format?.mime || 'audio/webm';
    voiceState.startedAt = Date.now();
    voiceState.seconds = 0;
    voiceState.abandoned = false;

    setVoicePreview(form, null);

    recorder.addEventListener('dataavailable', (event) => {
        if (event.data && event.data.size > 0) {
            voiceState.chunks.push(event.data);
        }
    });

    recorder.addEventListener('stop', () => finalizeVoiceRecording(form));

    recorder.start();
    startVoiceTicker(form);
    updateVoiceUi(form);
    setVoiceStatus(form, 'Gravando… 0:00. Toque em "Parar gravação" para revisar.');
}

function stopVoiceRecording(form) {
    const recorder = voiceState.recorder;

    if (!recorder) {
        return;
    }

    /*
     * `stop()` ainda entrega o último bloco em `dataavailable`; o estado é
     * limpo no listener de `stop` para não perder o fim da gravação.
     */
    if (recorder.state !== 'inactive') {
        recorder.stop();
    }

    stopVoiceTicker();
}

function discardVoice(form) {
    if (voiceState.recorder) {
        stopVoiceRecording(form);
    }

    clearVoiceCapture(form);
    setFormError(form, '');
    setVoiceStatus(form, `Grave até ${formatVoiceSeconds(voiceMaxSeconds(form))} segundos de áudio por mensagem.`);
}

/*
 * A atualização do bloco do chat substitui o formulário; uma captura em
 * andamento é encerrada sem enviar nada para não deixar o microfone aberto.
 */
function abandonVoiceCapture() {
    if (voiceState.recorder) {
        /*
         * `stop()` agenda o evento final: a flag `abandoned` impede que o
         * `finalizeVoiceRecording` reponha a prévia em um formulário que já
         * foi substituído.
         */
        voiceState.abandoned = true;

        try {
            voiceState.recorder.stop();
        } catch {
            // O gravador pode já estar inativo; nada a liberar.
        }
    }

    stopVoiceTicker();
    releaseVoiceStream();
    voiceState.recorder = null;
    voiceState.chunks = [];
    voiceState.blob = null;
    voiceState.mimeType = '';
    voiceState.seconds = 0;

    if (voiceState.previewUrl) {
        URL.revokeObjectURL(voiceState.previewUrl);
        voiceState.previewUrl = null;
    }
}

function voiceFormData(form) {
    const data = new FormData(form);

    data.set('duration', String(voiceState.seconds));
    data.set('mime_type', voiceState.mimeType);
    data.set('audio', voiceState.blob, `audio.${voiceExtensionFor(voiceState.mimeType)}`);

    return data;
}

function createAudioBubble(message) {
    const template = document.querySelector('[data-chat-audio-template]');
    const wrapper = template?.content?.firstElementChild?.cloneNode(true) ?? null;

    if (!wrapper) {
        const fallback = document.createElement('div');
        fallback.className = 'chat-audio';

        if (message.audio_url) {
            const player = document.createElement('audio');
            player.className = 'chat-audio__player';
            player.controls = true;
            player.preload = 'metadata';
            player.src = message.audio_url;

            fallback.append(player);
        }

        fallback.append(createTextElement('p', 'chat-audio__caption mb-0', 'Mensagem de voz'));

        return fallback;
    }

    const player = wrapper.querySelector('[data-chat-audio-player]');
    const duration = wrapper.querySelector('[data-chat-audio-duration]');

    if (player) {
        if (message.audio_url) {
            player.src = message.audio_url;
        } else {
            player.remove();
        }
    }

    if (duration) {
        if (message.audio_duration_label) {
            duration.textContent = `· ${message.audio_duration_label}`;
        } else {
            duration.remove();
        }
    }

    return wrapper;
}

function bindChatVoice(root = document) {
    root.querySelectorAll('[data-chat-voice-form]').forEach((form) => {
        if (form.dataset.chatVoiceBound === '1') {
            return;
        }

        form.dataset.chatVoiceBound = '1';

        const fields = voiceFields(form);

        if (!voiceSupported()) {
            fields.toggle?.setAttribute('disabled', 'disabled');
            setVoiceStatus(form, 'Este navegador não permite gravar áudio.');

            return;
        }

        fields.toggle?.addEventListener('click', () => {
            if (voiceState.recorder) {
                stopVoiceRecording(form);

                return;
            }

            startVoiceRecording(form);
        });

        fields.discard?.addEventListener('click', () => discardVoice(form));
    });
}

/*
 * Cartas enviadas juntas compartilham `batch_id` e vivem em um único bloco
 * (`[data-chat-cards]`), lado a lado. O contador "N cartas" só aparece quando o
 * bloco tem mais de uma carta, igual ao histórico renderizado no servidor.
 */
function cardBatchGroup(timeline, message) {
    if (!message?.batch_id) {
        return null;
    }

    return timeline.querySelector(`[data-message-batch="${message.batch_id}"]`);
}

function updateCardsCount(group) {
    const meta = group?.querySelector('.chat-message__meta');
    const total = group?.querySelectorAll('[data-message-id]').length ?? 0;

    if (!meta || total < 2) {
        return;
    }

    let label = meta.querySelector('[data-chat-cards-count]');

    if (!label) {
        label = createTextElement('span', 'chat-message__badge', '');
        label.dataset.chatCardsCount = '';
        meta.insertBefore(label, meta.querySelector('time'));
    }

    label.textContent = `${total} cartas`;
}

function createMessageElement(message, currentUserId) {
    const mine = Number(message.sender_id) === Number(currentUserId);
    const isCard = message.type === 'tarot_card';
    const isVoice = message.type === 'voice';
    const item = document.createElement('li');

    item.className = `chat-message${mine ? ' chat-message--mine' : ''}${isVoice ? ' chat-message--voice' : ''}`;

    if (isCard) {
        item.dataset.messageBatch = message.batch_id ?? '';
    } else {
        item.dataset.messageId = String(message.id);
    }

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
        const cards = document.createElement('div');
        cards.className = 'chat-cards';
        cards.dataset.chatCards = '';
        cards.append(createCardFigure(message));
        item.append(cards);
    } else if (isVoice) {
        item.append(createAudioBubble(message));
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

    const stream = chatStream(chat);
    const stayAtBottom = isNearStreamBottom(stream);
    const mine = Number(message.sender_id) === Number(chatState.userId);
    const batchGroup = cardBatchGroup(timeline, message);

    if (batchGroup) {
        /*
         * As cartas do mesmo envio chegam em sequência (resposta do formulário
         * ou um evento por carta). Cada uma entra no bloco já aberto: a conversa
         * não ganha uma mensagem nova por carta e a ordem do envio é mantida.
         */
        const cards = batchGroup.querySelector('[data-chat-cards]');

        if (!cards || cards.querySelector(`[data-message-id="${Number(message.id)}"]`)) {
            return false;
        }

        cards.append(createCardFigure(message));
        updateCardsCount(batchGroup);
    } else {
        const element = createMessageElement(message, chatState.userId);

        /*
         * A ordem cronológica é garantida mesmo quando o eco do broadcast chega
         * antes da resposta do envio (ou depois, em uma reconexão com backfill).
         */
        const next = Array.from(timeline.querySelectorAll('[data-message-id]')).find(
            (existing) => Number(existing.dataset.messageId) > Number(message.id),
        );

        if (next) {
            next.before(element);
        } else {
            timeline.append(element);
        }
    }

    timeline.classList.remove('d-none');
    chat.querySelector('[data-chat-empty]')?.classList.add('d-none');

    if (stayAtBottom || mine) {
        scrollStreamToBottom(stream);
        setJumpVisible(chat, false);
    } else {
        registerPendingMessage(chat);
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

        /*
         * O bloco do chat é substituído inteiro: qualquer gravação em andamento
         * (ou prévia ainda não enviada) é descartada para não manter o
         * microfone aberto nem deixar um áudio órfão na memória.
         */
        abandonVoiceCapture();

        ['#chat', '#consultation-progress', '#consultation-actions', '#consultation-status-summary'].forEach((selector) => {
            replaceFragment(documentFragment, selector);
        });

        const refreshed = currentChat();

        if (refreshed) {
            bindChatForms(refreshed);
            bindCardPicker(refreshed);
            bindChatStream(refreshed);
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
 * Aviso antes do envio.
 *
 * A checagem definitiva é sempre do servidor — a rota devolve 422 e registra a
 * ocorrência —, mas repetir as regras principais no navegador evita a ida e
 * volta e deixa claro o motivo do bloqueio antes da mensagem sair.
 */
const SENSITIVE_PATTERNS = [
    { label: 'CPF', pattern: /\b\d{3}\s*\.\s*\d{3}\s*\.\s*\d{3}\s*-\s*\d{2}\b/ },
    { label: 'CNPJ', pattern: /\b\d{2}\s*\.\s*\d{3}\s*\.\s*\d{3}\s*\/\s*\d{4}\s*-\s*\d{2}\b/ },
    { label: 'e-mail', pattern: /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i },
    { label: 'e-mail disfarçado', pattern: /\[\s*at\s*\]|\(\s*at\s*\)|\barroba\b/i },
    { label: 'link externo', pattern: /https?:\/\/|www\./i },
    {
        label: 'contato externo',
        pattern: /\bwa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|t\.me|telegram\.me|instagram\.com|facebook\.com|fb\.me|tiktok\.com|bit\.ly|linktr\.ee\b/i,
    },
    { label: 'telefone', pattern: /\(\d{2}\)\s*\d{4,5}[\s-]?\d{4}\b/ },
    { label: 'telefone', pattern: /\b\d{2}\s+9?\d{4}[\s-]\d{4}\b/ },
    { label: 'telefone', pattern: /\+\d{2}\s*\d{2}\s*\d{4,5}\s*\d{4}\b/ },
    { label: 'sequência de dígitos', pattern: /\b\d{10,11}\b/ },
    { label: 'contato disfarçado', pattern: /\b(?:(?:zero|um|dois|três|tres|quatro|cinco|seis|sete|oito|nove)\b[\s,.;:-]*){8,}/i },
];

function sensitiveLabels(text) {
    if (!text) {
        return [];
    }

    const labels = [];

    SENSITIVE_PATTERNS.forEach(({ label, pattern }) => {
        if (!labels.includes(label) && pattern.test(text)) {
            labels.push(label);
        }
    });

    return labels;
}

/*
 * Devolve `true` quando o participante decidiu enviar mesmo após o aviso.
 * Cancelar mantém o texto no campo para que ele possa reescrever.
 */
function confirmSensitiveSend(form, text) {
    const labels = sensitiveLabels(text);

    if (labels.length === 0) {
        return true;
    }

    const confirmed = window.confirm(
        `Sua mensagem parece conter ${labels.join(', ')}.\n\n` +
            'Por segurança, o chat não permite compartilhar dados pessoais nem contatos fora da plataforma: ' +
            'o servidor vai bloquear o envio.\n\nDeseja tentar enviar mesmo assim?',
    );

    if (!confirmed) {
        setFormError(
            form,
            `Remova ${labels.join(', ')} para continuar. Dados pessoais e contatos externos são bloqueados pelo servidor.`,
        );
    }

    return confirmed;
}

/*
 * Envio de cartas.
 *
 * O seletor aceita várias cartas de qualquer baralho: o filtro por baralho é
 * apenas visual, o limite publicado em `data-max-cards` é conferido aqui e
 * novamente no servidor (`ConsultationMessageManager::MAX_CARDS_PER_SEND`).
 */
function selectedCardInputs(form) {
    return Array.from(form?.querySelectorAll('[data-chat-card-input]') ?? []).filter((input) => input.checked);
}

function cardOption(input) {
    return input?.closest('[data-chat-card-option]') ?? null;
}

function updateCardSelection(form) {
    if (!form) {
        return;
    }

    const max = Number(form.dataset.maxCards ?? 1) || 1;
    const selected = selectedCardInputs(form);
    const counter = form.querySelector('[data-chat-card-counter]');

    if (counter) {
        counter.textContent = `${selected.length} de ${max} cartas selecionadas`;
    }

    const box = form.querySelector('[data-chat-selection]');
    const list = form.querySelector('[data-chat-selection-list]');

    if (!box || !list) {
        return;
    }

    list.replaceChildren();

    selected.forEach((input) => {
        const option = cardOption(input);
        const chip = document.createElement('li');
        chip.className = 'chat-card-picker__chip';

        if (option?.dataset.cardImage) {
            const image = document.createElement('img');
            image.className = 'chat-card-picker__chip-image';
            // A miniatura já está reduzida e cacheada; o atributo `data-card-image`
            // continua sendo o original usado pelo modal de pré-visualização.
            image.src = option.dataset.cardThumb ?? option.dataset.cardImage;
            image.alt = '';
            image.decoding = 'async';

            chip.append(image);
        }

        chip.append(createTextElement('span', 'chat-card-picker__chip-name', option?.dataset.cardName ?? 'Carta'));

        const remove = createTextElement('button', 'chat-card-picker__chip-remove', '×');
        remove.type = 'button';
        remove.setAttribute('aria-label', `Remover ${option?.dataset.cardName ?? 'carta'} da seleção`);
        remove.addEventListener('click', () => {
            input.checked = false;
            updateCardSelection(form);
        });

        chip.append(remove);
        list.append(chip);
    });

    box.classList.toggle('d-none', selected.length === 0);
}

function resetCardPicker(form) {
    form?.querySelectorAll('[data-chat-card-input]').forEach((input) => {
        input.checked = false;
    });

    updateCardSelection(form);
}

function bindCardPreview(form) {
    const modalElement = document.querySelector('[data-chat-card-preview-modal]');

    if (!modalElement || modalElement.dataset.chatPreviewBound === '1') {
        return;
    }

    modalElement.dataset.chatPreviewBound = '1';

    const modal = Modal.getOrCreateInstance(modalElement);
    const image = modalElement.querySelector('[data-chat-card-preview-image]');
    const title = modalElement.querySelector('[data-chat-card-preview-title]');
    const deck = modalElement.querySelector('[data-chat-card-preview-deck]');
    const select = modalElement.querySelector('[data-chat-card-preview-select]');
    let target = null;

    form?.querySelectorAll('[data-chat-card-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            target = cardOption(button.querySelector('[data-chat-card-input]')) ?? button.closest('[data-chat-card-option]');

            if (!target) {
                return;
            }

            const name = target.dataset.cardName ?? 'Carta';

            if (image) {
                image.src = target.dataset.cardImage ?? '';
                image.alt = `Carta ${name}`;
            }

            if (title) {
                title.textContent = name;
            }

            if (deck) {
                deck.textContent = target.dataset.cardDeck ?? '';
            }

            if (select) {
                select.textContent = selectedCardInputs(form).some(
                    (input) => cardOption(input) === target,
                )
                    ? 'Remover da seleção'
                    : 'Selecionar carta';
            }

            modal.show();
        });
    });

    select?.addEventListener('click', () => {
        const input = target?.querySelector('[data-chat-card-input]');

        if (input) {
            input.checked = !input.checked;
            updateCardSelection(form);
        }

        modal.hide();
    });
}

function bindCardPicker(root = document) {
    root.querySelectorAll('[data-chat-card-form]').forEach((form) => {
        updateCardSelection(form);
        bindCardPreview(form);

        if (form.dataset.chatCardBound === '1') {
            return;
        }

        form.dataset.chatCardBound = '1';

        const max = Number(form.dataset.maxCards ?? 1) || 1;

        form.querySelectorAll('[data-chat-card-input]').forEach((input) => {
            input.addEventListener('change', () => {
                if (selectedCardInputs(form).length > max) {
                    input.checked = false;
                    setFormError(form, `Você pode enviar no máximo ${max} cartas por vez. Desmarque uma antes de escolher outra.`);
                } else {
                    setFormError(form, '');
                }

                updateCardSelection(form);
            });
        });

        form.querySelector('[data-chat-deck-filter]')?.addEventListener('change', (event) => {
            const deckId = event.target.value;

            form.querySelectorAll('[data-chat-deck]').forEach((group) => {
                group.classList.toggle('d-none', deckId !== '' && group.dataset.chatDeck !== deckId);
            });
        });
    });
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
    const isCardForm = form.dataset.chatForm === 'card';
    const isVoiceForm = form.dataset.chatForm === 'voice';
    const maxCards = Number(form.dataset.maxCards ?? 1) || 1;
    const cards = isCardForm ? selectedCardInputs(form) : [];

    if (isVoiceForm && !voiceState.blob) {
        setFormError(form, 'Grave um áudio antes de enviar.');
        voiceFields(form).toggle?.focus();

        return;
    }

    if (body && !body.value.trim()) {
        setFormError(form, 'Escreva uma mensagem antes de enviar.');
        body.focus();

        return;
    }

    if (isCardForm && cards.length === 0) {
        setFormError(form, 'Escolha ao menos uma carta antes de enviar.');
        form.querySelector('[data-chat-card-input]')?.focus();

        return;
    }

    if (isCardForm && cards.length > maxCards) {
        setFormError(form, `Envie no máximo ${maxCards} cartas por vez.`);

        return;
    }

    if (body && !confirmSensitiveSend(form, body.value)) {
        body.classList.add('is-invalid');
        body.focus();

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
            body: isVoiceForm ? voiceFormData(form) : new FormData(form),
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
            const errors = payload.errors ?? {};
            const firstError = Object.values(errors).flat()[0];

            setFormError(form, firstError ?? payload.message ?? 'Não foi possível enviar a mensagem.');

            /*
             * Bloqueio por dado pessoal: o destaque fica no campo da mensagem
             * para que o texto continue visível e editável.
             */
            if (errors.body && body) {
                body.classList.add('is-invalid');
                body.focus();
            } else {
                form.querySelector('.is-invalid')?.focus();
            }

            return;
        }

        if (!response.ok) {
            throw new Error(`Falha ao enviar (${response.status}).`);
        }

        const payload = await response.json();
        const current = currentChat();
        const sent = Array.isArray(payload.messages)
            ? payload.messages
            : payload.message
              ? [payload.message]
              : [];

        sent.forEach((message) => insertMessage(current, message));

        /*
         * `form.reset()` não serve para os cartões: as caixas marcadas no HTML
         * voltam ao estado renderizado pelo servidor. A seleção é limpa à parte
         * para que o próximo envio comece vazio.
         */
        form.reset();
        resetCardPicker(form);

        if (isVoiceForm) {
            clearVoiceCapture(form);
            setVoiceStatus(form, `Grave até ${formatVoiceSeconds(voiceMaxSeconds(form))} segundos de áudio por mensagem.`);
        }

        setNotice(current, payload.notice ?? 'Mensagem enviada.');
        window.setTimeout(() => setNotice(currentChat(), ''), 4000);

        (form.querySelector('textarea[name="body"]') ?? form.querySelector('[data-chat-card-input]'))?.focus();
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
/*
 * Seletor de emoji do compositor.
 *
 * A lista é própria do projeto — nenhuma dependência nova entra no bundle — e o
 * painel é montado sob demanda, no primeiro clique, para não custar nada em
 * quem nunca abre. A inserção respeita a posição do cursor e o `maxlength` do
 * campo: um emoji não cabe pela metade, então quando não há espaço o envio é
 * bloqueado com aviso em vez de cortar o caractere.
 */
const CHAT_EMOJI_RECENT_KEY = 'arcana:chat-emoji-recent';
const CHAT_EMOJI_RECENT_LIMIT = 16;
const CHAT_EMOJI_GROUPS = [
    {
        id: 'rostos',
        label: 'Rostos',
        icon: '😊',
        emojis: '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😋 😎 🤩 🥳 😏 😢 😭 😤 😱 🤔 🤗'.split(' '),
    },
    {
        id: 'gestos',
        label: 'Gestos',
        icon: '👍',
        emojis: '👍 👎 👌 ✌️ 🤞 🤟 🤘 👏 🙌 🤲 🙏 💪 👋 🖐️ ☝️ 👉 👈 👆 👇 ✋ 🤝'.split(' '),
    },
    {
        id: 'coracoes',
        label: 'Corações',
        icon: '❤️',
        emojis: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 💐'.split(' '),
    },
    {
        id: 'tarot',
        label: 'Tarot',
        icon: '🔮',
        emojis: '🔮 ✨ 🌟 ⭐ 🌙 ☀️ 🕯️ 📿 🔔 🌈 🍀 💫 ☯️ 🌹 🌻 🌺 🌸 🦋 🪄 🎴 🃏'.split(' '),
    },
    {
        id: 'objetos',
        label: 'Objetos',
        icon: '🎁',
        emojis: '🎁 🎉 🎊 💎 💰 👑 📖 ✉️ 📌 🎯 🏆 🎵 🎶 ⏳ 🕰️ 📱 💻 🖊️ 🔑 🧿'.split(' '),
    },
    {
        id: 'comidas',
        label: 'Comidas',
        icon: '☕',
        emojis: '☕ 🍵 🍰 🍫 🍓 🍇 🍉 🍊 🥂 🍾'.split(' '),
    },
    {
        id: 'animais',
        label: 'Animais e natureza',
        icon: '🦋',
        emojis: '🐈 🐕 🦉 🦋 🐝 🐬 🦢 🕊️ 🌿 🌵'.split(' '),
    },
];

function readRecentEmojis() {
    try {
        const stored = JSON.parse(window.localStorage?.getItem(CHAT_EMOJI_RECENT_KEY) ?? '[]');

        return Array.isArray(stored) ? stored.filter((item) => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

function rememberEmoji(emoji) {
    const recent = [emoji, ...readRecentEmojis().filter((item) => item !== emoji)]
        .slice(0, CHAT_EMOJI_RECENT_LIMIT);

    try {
        window.localStorage?.setItem(CHAT_EMOJI_RECENT_KEY, JSON.stringify(recent));
    } catch {
        // Modo privado/navegador sem storage: os recentes valem só nesta página.
    }

    return recent;
}

/**
 * Monta o painel (abas + grade + recentes) na primeira abertura.
 */
function buildEmojiPanel(picker, form) {
    const panel = picker.querySelector('[data-chat-emoji-panel]');

    if (!panel || panel.dataset.chatEmojiBuilt === '1') {
        return panel;
    }

    panel.dataset.chatEmojiBuilt = '1';

    const recents = document.createElement('div');
    recents.className = 'chat-emoji__recents d-none';
    recents.dataset.chatEmojiRecents = '';

    const tabs = document.createElement('div');
    tabs.className = 'chat-emoji__tabs';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Categorias de emoji');

    const grid = document.createElement('div');
    grid.className = 'chat-emoji__grid';
    grid.dataset.chatEmojiGrid = '';

    CHAT_EMOJI_GROUPS.forEach((group, index) => {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'chat-emoji__tab';
        tab.dataset.chatEmojiTab = group.id;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
        tab.setAttribute('title', group.label);
        tab.setAttribute('aria-label', group.label);
        tab.textContent = group.icon;

        if (index === 0) {
            tab.classList.add('is-active');
        }

        tab.addEventListener('click', () => selectEmojiGroup(picker, group.id));
        tabs.append(tab);
    });

    panel.append(recents, tabs, grid);

    grid.addEventListener('keydown', (event) => {
        const items = [...grid.querySelectorAll('[data-chat-emoji]')];
        const index = items.indexOf(document.activeElement);

        if (index < 0) {
            return;
        }

        const columns = Math.max(1, getComputedStyle(grid).gridTemplateColumns.split(' ').length);
        const moves = {
            ArrowRight: index + 1,
            ArrowLeft: index - 1,
            ArrowDown: index + columns,
            ArrowUp: index - columns,
            Home: 0,
            End: items.length - 1,
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        items[Math.min(Math.max(moves[event.key], 0), items.length - 1)]?.focus();
    });

    panel.addEventListener('click', (event) => {
        const button = event.target.closest('[data-chat-emoji]');

        if (button) {
            insertEmoji(form, button.dataset.chatEmoji ?? '');
        }
    });

    renderRecentEmojis(picker);

    return selectEmojiGroup(picker, CHAT_EMOJI_GROUPS[0].id);
}

/**
 * Troca a categoria exibida mantendo o foco por teclado dentro da grade.
 */
function selectEmojiGroup(picker, groupId) {
    const panel = picker.querySelector('[data-chat-emoji-panel]');
    const grid = panel?.querySelector('[data-chat-emoji-grid]');
    const group = CHAT_EMOJI_GROUPS.find((item) => item.id === groupId) ?? CHAT_EMOJI_GROUPS[0];

    if (!panel || !grid) {
        return panel;
    }

    panel.querySelectorAll('[data-chat-emoji-tab]').forEach((tab) => {
        const active = tab.dataset.chatEmojiTab === group.id;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    grid.replaceChildren(...group.emojis.map((emoji) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'chat-emoji__item';
        button.dataset.chatEmoji = emoji;
        button.textContent = emoji;

        return button;
    }));

    panel.dataset.chatEmojiGroup = group.id;

    return panel;
}

function renderRecentEmojis(picker) {
    const panel = picker.querySelector('[data-chat-emoji-panel]');
    const recents = panel?.querySelector('[data-chat-emoji-recents]');
    const recent = readRecentEmojis();

    if (!recents) {
        return;
    }

    recents.replaceChildren(...recent.map((emoji) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'chat-emoji__item chat-emoji__item--recent';
        button.dataset.chatEmoji = emoji;
        button.textContent = emoji;

        return button;
    }));

    recents.classList.toggle('d-none', recent.length === 0);
}

/**
 * Insere o emoji na posição do cursor.
 *
 * O `maxlength` do `textarea` conta unidades UTF-16, então um emoji pode não
 * caber no fim do campo. Nesse caso nada é inserido — cortar o caractere ao
 * meio geraria texto inválido — e o aviso do formulário explica o limite.
 */
function insertEmoji(form, emoji) {
    const textarea = form?.querySelector('textarea[name="body"]');

    if (!textarea || !emoji) {
        return;
    }

    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? start;
    const next = `${textarea.value.slice(0, start)}${emoji}${textarea.value.slice(end)}`;
    const max = textarea.maxLength;

    if (max > 0 && next.length > max) {
        setFormError(form, `Limite de ${max} caracteres atingido. Remova um pouco do texto para incluir o emoji.`);

        return;
    }

    setFormError(form, '');
    textarea.value = next;

    const caret = start + emoji.length;
    textarea.setSelectionRange(caret, caret);
    textarea.focus();
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    rememberEmoji(emoji);
    renderRecentEmojis(form.querySelector('[data-chat-emoji]'));
}

function closeEmojiPicker(picker) {
    const panel = picker?.querySelector('[data-chat-emoji-panel]');
    const trigger = picker?.querySelector('[data-chat-emoji-trigger]');

    panel?.classList.add('d-none');
    trigger?.setAttribute('aria-expanded', 'false');
    picker?.removeAttribute('data-chat-emoji-open');
}

function openEmojiPicker(picker, form) {
    const trigger = picker.querySelector('[data-chat-emoji-trigger]');
    const panel = buildEmojiPanel(picker, form);

    panel?.classList.remove('d-none');
    trigger?.setAttribute('aria-expanded', 'true');
    picker.dataset.chatEmojiOpen = '1';

    panel?.querySelector('[data-chat-emoji]')?.focus();
}

/**
 * Liga o seletor de emoji em cada compositor presente na tela.
 *
 * Idempotente: `refreshState` substitui o bloco do chat e o binding roda de
 * novo, então o marcador `data-chat-emoji-bound` evita listeners duplicados.
 */
function bindEmojiPicker(root = document) {
    root.querySelectorAll('[data-chat-emoji]').forEach((picker) => {
        if (picker.dataset.chatEmojiBound === '1') {
            return;
        }

        picker.dataset.chatEmojiBound = '1';

        const form = picker.closest('form');
        const trigger = picker.querySelector('[data-chat-emoji-trigger]');

        trigger?.addEventListener('click', () => {
            if (picker.dataset.chatEmojiOpen === '1') {
                closeEmojiPicker(picker);

                return;
            }

            openEmojiPicker(picker, form);
        });

        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && picker.dataset.chatEmojiOpen === '1') {
                event.stopPropagation();
                closeEmojiPicker(picker);
                trigger?.focus();
            }
        });
    });

    if (chatState.emojiGlobalBound) {
        return;
    }

    chatState.emojiGlobalBound = true;

    // Clique fora do seletor fecha o painel; o listener vive no documento
    // porque o bloco do chat é substituído a cada atualização de estado.
    document.addEventListener('pointerdown', (event) => {
        document.querySelectorAll('[data-chat-emoji][data-chat-emoji-open="1"]').forEach((picker) => {
            if (!picker.contains(event.target)) {
                closeEmojiPicker(picker);
            }
        });
    });
}

function bindChatForms(root = document) {
    bindEmojiPicker(root);
    bindChatVoice(root);

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
        markIntentionalNavigation();
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
    bindCardPicker(chat);
    bindChatStream(chat);
    bindChatPreferences(chat);
    applyChatPreferences(chat);

    // O áudio só é liberado pelo navegador depois da primeira interação. Os
    // gestos continuam sendo o caminho principal; quem já liberou o som neste
    // navegador tem a retomada tentada no carregamento e na volta do foco.
    document.addEventListener('pointerdown', primeChatAudio, { once: true });
    document.addEventListener('keydown', primeChatAudio, { once: true });
    bindChatAudioWatch();

    if (chatAudioUnlockedBefore()) {
        attemptChatAutoplay();
    }

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

