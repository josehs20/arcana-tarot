/**
 * Comemoração do bônus de promoção creditado na recarga.
 *
 * O modal só é renderizado quando o pagamento foi confirmado com bônus. Aqui
 * ele abre sozinho uma única vez por sessão por pagamento, guardando a dispensa
 * em `sessionStorage`, para o cliente não ser perseguido a cada recarregamento.
 *
 * Sem JS (ou com o storage bloqueado) nada quebra: o alerta de sucesso da
 * página já informa o bônus creditado.
 */
import { Modal } from 'bootstrap';

const STORAGE_PREFIX = 'arcana:promotion-bonus-modal:';
const MODAL_SELECTOR = '[data-promotion-bonus-modal]';

function storageKey(paymentId) {
    return `${STORAGE_PREFIX}${paymentId}`;
}

function wasShown(paymentId) {
    try {
        return window.sessionStorage.getItem(storageKey(paymentId)) === 'shown';
    } catch (error) {
        // Storage bloqueado (modo privado): o modal aparece normalmente.
        return false;
    }
}

function rememberShown(paymentId) {
    try {
        window.sessionStorage.setItem(storageKey(paymentId), 'shown');
    } catch (error) {
        // Sem storage o modal reaparece no próximo carregamento; nada quebra.
    }
}

function openModal(modal) {
    const paymentId = modal.dataset.paymentId;

    if (!paymentId || wasShown(paymentId)) {
        return;
    }

    Modal.getOrCreateInstance(modal).show();

    modal.addEventListener('hidden.bs.modal', () => {
        rememberShown(paymentId);
    }, { once: true });
}

document.querySelectorAll(MODAL_SELECTOR).forEach(openModal);
