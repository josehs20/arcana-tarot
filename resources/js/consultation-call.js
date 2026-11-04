/**
 * Chamada de áudio e vídeo da consulta (WebRTC).
 *
 * O servidor transporta apenas sinalização (oferta, resposta e candidatos
 * ICE). O áudio/vídeo trafega direto entre os navegadores. O estado e os
 * sinais são sincronizados pelo canal privado da consulta (Reverb) e também
 * por polling autenticado, garantindo funcionamento mesmo sem WebSocket.
 */
const callState = {
    section: null,
    role: null,
    userId: 0,
    consultationId: 0,
    consultationStatus: null,
    requiresVideo: false,
    call: null,
    pc: null,
    localStream: null,
    iceServers: [],
    pendingCandidates: [],
    remoteDescriptionSet: false,
    lastSignalId: 0,
    pollMs: 2500,
    polling: false,
    startingPeer: false,
    connectedReported: false,
    failureReported: false,
    endedHandled: false,
    initiallyActive: false,
};

function callElement(selector) {
    return callState.section?.querySelector(selector) ?? null;
}

function parseJson(value) {
    if (!value) {
        return null;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        return null;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function callApi(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;

        throw new Error(firstError || data.message || 'Não foi possível concluir a ação da chamada.');
    }

    return data;
}

function setCallNotice(message) {
    const notice = callElement('[data-call-notice]');

    if (!notice) {
        return;
    }

    notice.textContent = message ?? '';
    notice.classList.toggle('d-none', !message);
}

function setCallConnection(label, tone) {
    const badge = callElement('[data-call-connection]');

    if (!badge) {
        return;
    }

    badge.className = `badge bg-${tone ?? 'secondary'}`;
    badge.textContent = label;
}

function setCallStatus(label, tone) {
    const badge = callElement('[data-call-status]');

    if (!badge) {
        return;
    }

    badge.className = `badge bg-${tone ?? 'secondary'}`;
    badge.textContent = label;
}

function statusTone(status) {
    if (status === 'connected') {
        return 'success';
    }

    if (status === 'ringing' || status === 'accepted') {
        return 'warning';
    }

    if (status === 'failed') {
        return 'danger';
    }

    return 'secondary';
}

function setButton(button, visible, disabled = false) {
    if (!button) {
        return;
    }

    button.hidden = !visible;
    button.disabled = disabled;
}

function renderCall() {
    const call = callState.call;
    const status = call?.status ?? null;
    const active = callState.consultationStatus === 'active';
    const isClient = callState.role === 'client';
    const isAttendant = callState.role === 'attendant';
    const placeholder = callElement('[data-call-placeholder]');
    const hint = callElement('[data-call-hint]');

    setCallStatus(call?.status_label ?? 'Sem chamada', statusTone(status));

    setButton(callElement('[data-call-request]'), isClient && active && !call);
    setButton(callElement('[data-call-accept]'), isAttendant && status === 'ringing');
    setButton(callElement('[data-call-reject]'), isAttendant && status === 'ringing');

    const canHangup = status === 'ringing' || status === 'accepted' || status === 'connected';
    setButton(callElement('[data-call-hangup]'), canHangup && (isClient || isAttendant));

    const mediaReady = status === 'accepted' || status === 'connected';
    setButton(callElement('[data-call-mute]'), mediaReady);
    setButton(callElement('[data-call-camera]'), mediaReady && callState.requiresVideo);

    if (placeholder) {
        placeholder.classList.toggle('d-none', status === 'connected' && callState.pc !== null);
    }

    if (!hint) {
        return;
    }

    if (callState.consultationStatus === 'requested') {
        hint.textContent = 'A chamada será liberada quando o atendente aceitar a consulta.';
    } else if (callState.consultationStatus === 'active' && !call) {
        hint.textContent = isClient
            ? 'Toque em “Iniciar chamada” para falar com o atendente.'
            : 'Aguardando o cliente iniciar a chamada.';
    } else if (status === 'ringing') {
        hint.textContent = isClient ? 'Chamando o atendente…' : 'Chamada recebida. Aceite para atender.';
    } else if (status === 'accepted') {
        hint.textContent = 'Conectando os navegadores…';
    } else if (status === 'connected') {
        hint.textContent = 'Chamada em andamento.';
    } else if (status === 'ended' || status === 'failed') {
        hint.textContent = call?.ended_reason_label ?? 'Chamada encerrada.';
    } else if (callState.consultationStatus && callState.consultationStatus !== 'active') {
        hint.textContent = 'Esta consulta está encerrada.';
    }
}

async function ensureMedia() {
    if (callState.localStream) {
        return callState.localStream;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Seu navegador não oferece captura de áudio/vídeo (getUserMedia).');
    }

    callState.localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: callState.requiresVideo,
    });

    const localVideo = callElement('[data-call-local]');

    if (localVideo) {
        localVideo.srcObject = callState.localStream;
    }

    return callState.localStream;
}

function stopMedia() {
    callState.localStream?.getTracks().forEach((track) => track.stop());
    callState.localStream = null;

    if (callState.pc) {
        callState.pc.onicecandidate = null;
        callState.pc.ontrack = null;
        callState.pc.onconnectionstatechange = null;
        callState.pc.close();
        callState.pc = null;
    }

    const remote = callElement('[data-call-remote]');

    if (remote) {
        remote.srcObject = null;
    }

    const localVideo = callElement('[data-call-local]');

    if (localVideo) {
        localVideo.srcObject = null;
    }

    callState.remoteDescriptionSet = false;
    callState.pendingCandidates = [];
}

async function sendSignal(type, payload) {
    try {
        const data = await callApi('POST', callState.section.dataset.signalUrl, { type, payload });

        if (data.signal?.id) {
            callState.lastSignalId = Math.max(callState.lastSignalId, Number(data.signal.id));
        }

        if (data.call) {
            callState.call = data.call;
            renderCall();
        }
    } catch (error) {
        setCallNotice(error.message);
    }
}

async function flushCandidates() {
    if (!callState.pc || !callState.remoteDescriptionSet) {
        return;
    }

    const pending = callState.pendingCandidates;
    callState.pendingCandidates = [];

    for (const candidate of pending) {
        try {
            await callState.pc.addIceCandidate(candidate);
        } catch (error) {
            // Candidatos expirados não interrompem a chamada.
        }
    }
}

async function handleSignal(signal) {
    if (!signal || !signal.type) {
        return;
    }

    if (Number(signal.from_user_id) === callState.userId) {
        return;
    }

    if (signal.type === 'ice') {
        const candidate = signal.payload?.candidate;

        if (!candidate) {
            return;
        }

        if (callState.pc && callState.remoteDescriptionSet) {
            try {
                await callState.pc.addIceCandidate(candidate);
            } catch (error) {
                // Ignora candidato inválido/expirado.
            }
        } else {
            callState.pendingCandidates.push(candidate);
        }

        return;
    }

    if (signal.type === 'offer' && callState.role === 'attendant' && !callState.remoteDescriptionSet) {
        if (!callState.pc) {
            await startPeer(false);
        }

        if (!callState.pc) {
            return;
        }

        await callState.pc.setRemoteDescription({ type: 'offer', sdp: signal.payload?.sdp });
        callState.remoteDescriptionSet = true;
        await flushCandidates();

        const answer = await callState.pc.createAnswer();
        await callState.pc.setLocalDescription(answer);
        await sendSignal('answer', { type: 'answer', sdp: callState.pc.localDescription.sdp });

        return;
    }

    if (signal.type === 'answer' && callState.role === 'client' && callState.pc && !callState.remoteDescriptionSet) {
        await callState.pc.setRemoteDescription({ type: 'answer', sdp: signal.payload?.sdp });
        callState.remoteDescriptionSet = true;
        await flushCandidates();
    }
}

async function startPeer(isCaller) {
    if (callState.pc || callState.startingPeer) {
        return;
    }

    callState.startingPeer = true;

    try {
        const stream = await ensureMedia();
        const pc = new RTCPeerConnection({ iceServers: callState.iceServers });

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal('ice', { candidate: event.candidate.toJSON() });
            }
        };

        pc.ontrack = (event) => {
            const remote = callElement('[data-call-remote]');

            if (remote) {
                remote.srcObject = event.streams[0] ?? new MediaStream([event.track]);
            }

            callElement('[data-call-placeholder]')?.classList.add('d-none');
        };

        pc.onconnectionstatechange = () => handleConnectionState(pc.connectionState);

        stream.getTracks().forEach((track) => pc.addTrack(track, stream));

        callState.pc = pc;

        if (isCaller) {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            await sendSignal('offer', { type: 'offer', sdp: pc.localDescription.sdp });
        }
    } catch (error) {
        setCallNotice(error.message || 'Não foi possível acessar microfone/câmera.');
        stopMedia();
    } finally {
        callState.startingPeer = false;
    }
}

function maybeStartCaller() {
    if (callState.role !== 'client') {
        return;
    }

    if (callState.call?.status !== 'accepted' || callState.consultationStatus !== 'active') {
        return;
    }

    startPeer(true);
}

async function handleConnectionState(connectionState) {
    if (connectionState === 'connected') {
        setCallNotice(null);
        callElement('[data-call-placeholder]')?.classList.add('d-none');

        if (!callState.connectedReported) {
            callState.connectedReported = true;

            try {
                const data = await callApi('PATCH', callState.section.dataset.connectedUrl);
                applyPayload(data);
            } catch (error) {
                setCallNotice(error.message);
            }
        }

        return;
    }

    if (connectionState === 'failed') {
        if (!callState.failureReported) {
            callState.failureReported = true;
            setCallNotice('A conexão da chamada falhou. Encerrando a consulta para interromper a cobrança.');
            await failCall('connection_failed');
        }

        return;
    }

    if (connectionState === 'disconnected') {
        setCallNotice('Conexão instável. Tentando reconectar…');
    }
}

async function failCall(reason) {
    try {
        const data = await callApi('PATCH', callState.section.dataset.failUrl, { reason });
        applyPayload(data);
    } catch (error) {
        setCallNotice(error.message);
    } finally {
        stopMedia();
        handleEndedConsultation();
    }
}

function handleEndedConsultation() {
    if (callState.endedHandled || !callState.initiallyActive) {
        return;
    }

    if (callState.consultationStatus !== 'active') {
        callState.endedHandled = true;
        stopMedia();
        window.setTimeout(() => window.location.reload(), 2000);
    }
}

function applyPayload(data) {
    if (data?.call !== undefined) {
        callState.call = data.call;
    }

    if (data?.consultation) {
        callState.consultationStatus = data.consultation.status ?? callState.consultationStatus;
    }

    renderCall();
    maybeStartCaller();
}

async function pollCall() {
    if (callState.polling) {
        return;
    }

    callState.polling = true;

    try {
        const separator = callState.section.dataset.stateUrl.includes('?') ? '&' : '?';
        const data = await callApi(
            'GET',
            `${callState.section.dataset.stateUrl}${separator}after=${callState.lastSignalId}`,
        );

        callState.consultationStatus = data.consultation?.status ?? callState.consultationStatus;
        callState.call = data.call;

        if (Array.isArray(data.ice_servers) && data.ice_servers.length > 0) {
            callState.iceServers = data.ice_servers;
        }

        if (data.call?.status === 'ended' || data.call?.status === 'failed') {
            stopMedia();
        }

        renderCall();

        for (const signal of data.signals ?? []) {
            const id = Number(signal.id ?? 0);

            if (id <= callState.lastSignalId) {
                continue;
            }

            callState.lastSignalId = id;
            await handleSignal(signal);
        }

        maybeStartCaller();
        handleEndedConsultation();
    } catch (error) {
        setCallConnection('Sincronização indisponível', 'danger');
    } finally {
        callState.polling = false;
    }
}

function subscribeEcho() {
    const echo = typeof window.initializeEcho === 'function' ? window.initializeEcho() : null;

    if (!echo) {
        setCallConnection('Sincronização por polling', 'warning');
        return;
    }

    try {
        echo.private(`consultation.${callState.consultationId}`)
            .listen('.consultation.call', (payload) => {
                if (payload?.call !== undefined) {
                    callState.call = payload.call;
                }

                if (payload?.signal) {
                    callState.lastSignalId = Math.max(callState.lastSignalId, Number(payload.signal.id ?? 0));
                    handleSignal(payload.signal);
                }

                renderCall();
                maybeStartCaller();
                handleEndedConsultation();
            })
            .listen('.consultation.state', () => pollCall());

        setCallConnection('Tempo real conectado', 'success');
    } catch (error) {
        setCallConnection('Sincronização por polling', 'warning');
    }
}

function bindCallControls() {
    const section = callState.section;

    section.querySelector('[data-call-request]')?.addEventListener('click', async (event) => {
        await withBusy(event.currentTarget, async () => {
            try {
                await ensureMedia();
            } catch (error) {
                setCallNotice(error.message || 'Permissão de microfone/câmera negada.');
                stopMedia();

                return;
            }

            const data = await callApi('POST', section.dataset.requestUrl);
            applyPayload(data);
            setCallNotice('Chamada solicitada. Aguardando o aceite do atendente.');
        });
    });

    section.querySelector('[data-call-accept]')?.addEventListener('click', async (event) => {
        await withBusy(event.currentTarget, async () => {
            try {
                await ensureMedia();
            } catch (error) {
                setCallNotice(error.message || 'Permissão de microfone/câmera negada.');
                stopMedia();

                return;
            }

            const data = await callApi('PATCH', section.dataset.acceptUrl);
            applyPayload(data);
            setCallNotice('Chamada aceita. Conectando…');
        });
    });

    section.querySelector('[data-call-reject]')?.addEventListener('click', async (event) => {
        await withBusy(event.currentTarget, async () => {
            const data = await callApi('PATCH', section.dataset.rejectUrl);
            applyPayload(data);
            stopMedia();
            handleEndedConsultation();
        });
    });

    section.querySelector('[data-call-hangup]')?.addEventListener('click', async (event) => {
        await withBusy(event.currentTarget, async () => {
            const data = await callApi('PATCH', section.dataset.endUrl);
            applyPayload(data);
            stopMedia();
            handleEndedConsultation();
        });
    });

    section.querySelector('[data-call-mute]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        const tracks = callState.localStream?.getAudioTracks() ?? [];

        if (tracks.length === 0) {
            return;
        }

        const enabled = !tracks[0].enabled;
        tracks.forEach((track) => { track.enabled = enabled; });

        button.setAttribute('aria-pressed', String(!enabled));
        button.classList.toggle('is-active', !enabled);

        const label = button.querySelector('[data-call-mute-label]');

        if (label) {
            label.textContent = enabled ? 'Silenciar microfone' : 'Ativar microfone';
        }
    });

    section.querySelector('[data-call-camera]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        const tracks = callState.localStream?.getVideoTracks() ?? [];

        if (tracks.length === 0) {
            return;
        }

        const enabled = !tracks[0].enabled;
        tracks.forEach((track) => { track.enabled = enabled; });

        button.setAttribute('aria-pressed', String(!enabled));
        button.classList.toggle('is-active', !enabled);

        const label = button.querySelector('[data-call-camera-label]');

        if (label) {
            label.textContent = enabled ? 'Desligar câmera' : 'Ligar câmera';
        }
    });
}

async function withBusy(button, callback) {
    if (button.disabled) {
        return;
    }

    button.disabled = true;

    try {
        await callback();
    } catch (error) {
        setCallNotice(error.message || 'Falha ao executar a ação da chamada.');
    } finally {
        button.disabled = false;
    }
}

function bootCall() {
    const section = document.querySelector('[data-call]');

    if (!section) {
        return;
    }

    /*
     * O administrador acompanha apenas metadados: os endpoints de chamada
     * negam acesso e nao existe midia a negociar. Consultas ja encerradas
     * tambem nao precisam de polling (evita recarregar a pagina em loop).
     */
    if (section.dataset.role === 'admin') {
        return;
    }

    callState.section = section;
    callState.role = section.dataset.role;
    callState.userId = Number(section.dataset.userId ?? 0);
    callState.consultationId = Number(section.dataset.consultationId ?? 0);
    callState.consultationStatus = section.dataset.status;
    callState.initiallyActive = section.dataset.status === 'active';
    callState.requiresVideo = section.dataset.requiresVideo === '1';
    callState.pollMs = Number(section.dataset.pollMs ?? 2500);
    callState.iceServers = parseJson(section.dataset.iceServers) ?? [];
    callState.call = parseJson(section.dataset.call);

    bindCallControls();
    renderCall();
    subscribeEcho();

    if (!callState.initiallyActive) {
        return;
    }

    pollCall();
    window.setInterval(pollCall, callState.pollMs);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootCall);
} else {
    bootCall();
}
