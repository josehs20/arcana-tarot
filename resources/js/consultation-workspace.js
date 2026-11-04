/**
 * Tela dedicada de atendimento.
 *
 * Todos os números oficiais (minutos cobrados, valor total, comissão) vêm de
 * `consultations.metrics` e são calculados no servidor. Este módulo só busca o
 * payload: na abertura, no próximo limite de minuto informado pelo próprio
 * servidor (`next_refresh_in_seconds`), ao voltar para a aba e a cada transição
 * de estado recebida pelo Reverb. Quando a consulta deixa de estar ativa, o
 * usuário é levado para a tela de histórico e recibo.
 */
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

const workspaceState = {
    loading: false,
    navigating: false,
    refreshTimer: null,
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

    applyStatus(payload.status, payload.status_label);
    scheduleRefresh(payload.next_refresh_in_seconds);
}

function scheduleRefresh(seconds) {
    window.clearTimeout(workspaceState.refreshTimer);

    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
        return;
    }

    workspaceState.refreshTimer = window.setTimeout(
        () => refreshMetrics(),
        Math.max(5, seconds) * 1000 + 250,
    );
}

function leaveWorkspace(root) {
    if (workspaceState.navigating) {
        return;
    }

    workspaceState.navigating = true;
    window.clearTimeout(workspaceState.refreshTimer);
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
