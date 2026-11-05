/**
 * Convite para avaliar a consulta recém-encerrada.
 *
 * O modal só é renderizado quando o cliente tem direito a depor (consulta
 * `ended` sem avaliação). Aqui ele abre sozinho uma única vez por sessão: a
 * dispensa fica em `sessionStorage` por consulta, para o convite não perseguir
 * o cliente a cada recarregamento da tela.
 *
 * Quando o envio falha na validação, o servidor devolve a página com erro e o
 * modal recebe `data-force-open="true"`: nesse caso a dispensa é ignorada e o
 * formulário reabre com a mensagem do servidor.
 */
import { Modal } from 'bootstrap';

const STORAGE_PREFIX = 'arcana:testimonial-prompt:';

function storageKey(consultationId) {
    return `${STORAGE_PREFIX}${consultationId}`;
}

function wasDismissed(modal) {
    if (modal.dataset.forceOpen === 'true') {
        return false;
    }

    try {
        return window.sessionStorage.getItem(storageKey(modal.dataset.consultationId)) === 'dismissed';
    } catch (error) {
        // Storage bloqueado (modo privado): o convite aparece normalmente.
        return false;
    }
}

function rememberDismissal(modal) {
    try {
        window.sessionStorage.setItem(storageKey(modal.dataset.consultationId), 'dismissed');
    } catch (error) {
        // Sem storage o convite reaparece no próximo carregamento; nada quebra.
    }
}

function openPrompt(modal) {
    if (!modal.dataset.consultationId || wasDismissed(modal)) {
        return;
    }

    Modal.getOrCreateInstance(modal).show();

    modal.addEventListener('hidden.bs.modal', () => {
        rememberDismissal(modal);
    }, { once: true });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-testimonial-prompt]').forEach(openPrompt);
});
