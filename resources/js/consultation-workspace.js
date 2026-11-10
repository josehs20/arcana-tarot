/**
 * Tela dedicada de atendimento.
 *
 * Todos os números oficiais (minutos cobrados, valor total, comissão, tempo
 * restante de saldo) vêm de `consultations.metrics` e são calculados no
 * servidor. Este módulo só busca o payload: na abertura, no próximo limite de
 * minuto informado pelo próprio servidor (`next_refresh_in_seconds`), ao voltar
 * para a aba e a cada transição de estado recebida pelo Reverb. Entre uma busca
 * e outra apenas o contador de tempo restante é decrementado localmente. Quando
 * a consulta deixa de estar ativa, o usuário é levado para a tela de histórico
 * e recibo.
 *
 * O único alerta disparado por aqui é o de saldo baixo do cliente: o limite
 * (`data-remaining-threshold-seconds`) e o valor comparado
 * (`remaining_seconds`) vêm do servidor, então o navegador não decide nada.
 */
import { Modal } from 'bootstrap';

import { markIntentionalNavigation } from './navigation-intent';

const WORKSPACE_STATUS = {
    requested: { dot: 'busy', text: 'status-text--busy', label: 'Solicitada' },
    active: { dot: 'online', text: 'status-text--online', label: 'Em andamento' },
    ended: { dot: 'offline', text: 'status-text--offline', label: 'Encerrada' },
    cancelled: { dot: 'offline', text: 'status-text--offline', label: 'Cancelada' },
    rejected: { dot: 'offline', text: 'status-text--offline', label: 'Recusada' },
    expired: { dot: 'offline', text: 'status-text--offline', label: 'Expirada' },
    interrupted: { dot: 'offline', text: 'status-text--offline', label: 'Interrompida' },
};

const RETRY_DELAY_SECONDS = 30;
const COUNTDOWN_TICK_MS = 1000;

const workspaceState = {
    loading: false,
    navigating: false,
    refreshTimer: null,
    countdownTimer: null,
    countdownDeadline: 0,
    lowBalanceShown: false,
};

function workspaceRoot() {
    return document.querySelector('[data-consultation-workspace]');
}

function applyStatus(status, label) {
    const wrapper = document.querySelector('#consultation-workspace-status');
    const entry = WORKSPACE_STATUS[status];

    if (!wrapper || !entry) {
        return;
    }

    const dot = wrapper.querySelector('.status-dot');
    const text = wrapper.querySelector('[class*="status-text--"]');

    if (dot) {
        dot.className = `status-dot status-dot--${entry.dot}`;
    }

    if (text) {
        text.className = entry.text;
        text.textContent = label ?? entry.label;
    }
}

function applyMetrics(payload) {
    document.querySelectorAll('[data-metric]').forEach((element) => {
        const value = payload[element.dataset.metric];

        if (value === undefined || value === null) {
            return;
        }

        element.textContent = value;
    });

    startCountdown(payload.remaining_seconds);
    applyStatus(payload.status, payload.status_label);
    scheduleRefresh(payload.next_refresh_in_seconds);
}

/*
 * Contagem regressiva até o saldo do cliente acabar.
 *
 * O total de segundos é calculado no servidor (`remaining_seconds`) e o
 * navegador só o decrementa até a próxima busca do payload, que acontece no
 * limite do minuto cobrado. Assim a tela não inventa números: valor cobrado,
 * comissão e tempo restante sempre partem de `consultations.metrics`.
 */
function formatCountdown(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;
    const pad = (value) => String(value).padStart(2, '0');

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${pad(minutes)}:${pad(rest)}`;
}

function renderCountdown() {
    const elements = document.querySelectorAll('[data-consultation-countdown]');

    if (elements.length === 0) {
        return;
    }

    const remaining = Math.max(0, Math.round((workspaceState.countdownDeadline - Date.now()) / 1000));

    elements.forEach((element) => {
        element.textContent = formatCountdown(remaining);
        element.classList.toggle('is-empty', remaining === 0);
    });

    maybeWarnLowBalance(remaining);
}

/*
 * Aviso de saldo baixo, exclusivo do cliente.
 *
 * O limite chega do servidor em `data-remaining-threshold-seconds` e o valor
 * comparado é o próprio `remaining_seconds` de `consultations.metrics`. O modal
 * aparece uma vez por carregamento da tela — repetir o alerta a cada minuto
 * atrapalharia a conversa — e o encerramento por saldo insuficiente continua
 * sendo decidido no servidor
 * (`ConsultationLifecycleManager::closeIfOutOfCredits`).
 */
function maybeWarnLowBalance(remainingSeconds) {
    if (workspaceState.lowBalanceShown) {
        return;
    }

    const modalElement = document.querySelector('[data-consultation-low-balance-warning]');

    if (!modalElement) {
        return;
    }

    const threshold = Number(modalElement.dataset.remainingThresholdSeconds);

    if (!Number.isFinite(threshold) || threshold <= 0) {
        return;
    }

    if (typeof remainingSeconds !== 'number' || !Number.isFinite(remainingSeconds) || remainingSeconds > threshold) {
        return;
    }

    workspaceState.lowBalanceShown = true;
    Modal.getOrCreateInstance(modalElement).show();
}

function startCountdown(seconds) {
    window.clearInterval(workspaceState.countdownTimer);

    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
        return;
    }

    workspaceState.countdownDeadline = Date.now() + Math.max(0, seconds) * 1000;
    renderCountdown();
    workspaceState.countdownTimer = window.setInterval(renderCountdown, COUNTDOWN_TICK_MS);
}

/*
 * O próximo minuto é cobrado por um comando agendado
 * (`consultations:charge-minutes`). A folga de 2s evita buscar os números no
 * mesmo instante do limite e receber ainda o valor anterior — comissão e valor
 * cobrado precisam acompanhar cada minuto iniciado.
 */
function scheduleRefresh(seconds) {
    window.clearTimeout(workspaceState.refreshTimer);

    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
        return;
    }

    workspaceState.refreshTimer = window.setTimeout(
        () => refreshMetrics(),
        Math.max(5, seconds) * 1000 + 2000,
    );
}

function leaveWorkspace(root) {
    if (workspaceState.navigating) {
        return;
    }

    workspaceState.navigating = true;
    window.clearTimeout(workspaceState.refreshTimer);
    window.clearInterval(workspaceState.countdownTimer);

    // O servidor confirmou o fim do atendimento: a saída é esperada e não deve
    // abrir o aviso nativo de navegação.
    markIntentionalNavigation();
    window.location.assign(root.dataset.showUrl);
}

async function refreshMetrics() {
    const root = workspaceRoot();

    if (!root || workspaceState.loading || workspaceState.navigating) {
        return;
    }

    workspaceState.loading = true;

    try {
        const response = await fetch(root.dataset.metricsUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.status === 403 || response.status === 404) {
            leaveWorkspace(root);

            return;
        }

        if (!response.ok) {
            throw new Error(`Falha ao atualizar os indicadores (${response.status}).`);
        }

        const payload = await response.json();

        if (payload.status && payload.status !== 'active') {
            leaveWorkspace(root);

            return;
        }

        applyMetrics(payload);
    } catch {
        // Mantém os últimos valores conhecidos e tenta novamente em instantes.
        scheduleRefresh(RETRY_DELAY_SECONDS);
    } finally {
        workspaceState.loading = false;
    }
}

function bindWorkspaceRealtime(root) {
    const echo = window.initializeEcho?.();

    if (!echo) {
        return;
    }

    echo.private(`consultation.${root.dataset.consultationId}`)
        .listen('.consultation.state', () => refreshMetrics());
}

function initializeWorkspace() {
    const root = workspaceRoot();

    if (!root) {
        return;
    }

    bindWorkspaceRealtime(root);

    // Primeira busca já define o horário do próximo limite de minuto cobrado.
    refreshMetrics();

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refreshMetrics();
        }
    });

    window.addEventListener('online', () => refreshMetrics());

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            refreshMetrics();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWorkspace);
} else {
    initializeWorkspace();
}
