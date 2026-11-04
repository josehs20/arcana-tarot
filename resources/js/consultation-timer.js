/**
 * Contador informativo da consulta.
 *
 * A origem do tempo é sempre `started_at` do servidor. O navegador só formata
 * a diferença; nenhum valor de saldo ou cobrança é calculado aqui.
 */
function formatElapsed(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remaining = seconds % 60;
    const pad = (value) => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(remaining)}`
        : `${pad(minutes)}:${pad(remaining)}`;
}

function updateConsultationTimers() {
    const now = Date.now();

    document.querySelectorAll('[data-consultation-timer]').forEach((element) => {
        const startedAt = Date.parse(element.dataset.startedAt ?? '');

        if (Number.isNaN(startedAt)) {
            return;
        }

        const endedAt = element.dataset.endedAt ? Date.parse(element.dataset.endedAt) : NaN;
        const effectiveEnd = Number.isNaN(endedAt) ? now : endedAt;

        element.textContent = formatElapsed((effectiveEnd - startedAt) / 1000);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateConsultationTimers();
    window.setInterval(updateConsultationTimers, 1000);
});

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        updateConsultationTimers();
    }
});
