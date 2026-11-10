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
 *
 * Também cuida das estrelas da nota: o campo enviado continua sendo o grupo de
 * rádios `name="rating"` (funciona sem JS), e aqui só acendemos as estrelas até
 * a nota escolhida — com pré-visualização no hover antes do clique.
 */
import { Modal } from 'bootstrap';

const STORAGE_PREFIX = 'arcana:testimonial-prompt:';
const STAR_GROUP_SELECTOR = '[data-star-rating]';
const STAR_FILLED_CLASS = 'star-rating__star--filled';
const STAR_VALUE_PLACEHOLDER = 'Escolha de 1 a 5 estrelas';

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

function selectedStarCount(group) {
    const checked = group.querySelector('input[name="rating"]:checked');

    return checked ? Number(checked.value) : 0;
}

/**
 * Acende as estrelas de 1 até `value`; com `value` zero, todas ficam apagadas.
 */
function paintStars(group, value) {
    group.querySelectorAll('[data-star-rating-star]').forEach((star) => {
        star.classList.toggle(STAR_FILLED_CLASS, Number(star.dataset.starRatingStar) <= value);
    });

    const caption = group.querySelector('[data-star-rating-value]');

    if (caption) {
        caption.textContent = value > 0
            ? `${value} ${value === 1 ? 'estrela' : 'estrelas'}`
            : STAR_VALUE_PLACEHOLDER;
    }
}

function initStarRating(group) {
    group.querySelectorAll('[data-star-rating-star]').forEach((star) => {
        // Pré-visualização: passar o mouse acende até a estrela apontada.
        star.addEventListener('pointerenter', () => paintStars(group, Number(star.dataset.starRatingStar)));
    });

    // Ao sair do grupo, ao trocar a nota e ao navegar por teclado, o desenho
    // volta a refletir o rádio marcado.
    group.addEventListener('pointerleave', () => paintStars(group, selectedStarCount(group)));
    group.addEventListener('change', () => paintStars(group, selectedStarCount(group)));
    group.addEventListener('focusin', (event) => {
        if (event.target.matches('input[type="radio"]')) {
            paintStars(group, Number(event.target.value));
        }
    });
    group.addEventListener('focusout', () => paintStars(group, selectedStarCount(group)));

    paintStars(group, selectedStarCount(group));
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(STAR_GROUP_SELECTOR).forEach(initStarRating);
    document.querySelectorAll('[data-testimonial-prompt]').forEach(openPrompt);
});
