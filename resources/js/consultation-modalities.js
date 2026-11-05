/**
 * Seleção de modalidade no perfil público do atendente.
 *
 * Antes de enviar a solicitação, confere o saldo do cliente para a modalidade
 * escolhida e, no áudio e no vídeo, se o navegador consegue capturar microfone
 * (e câmera). A permissão é pedida uma única vez e as faixas são liberadas em
 * seguida; quem valida preço e disponibilidade continua sendo o servidor.
 *
 * O mesmo formulário mostra o saldo do cliente e o mínimo exigido para a
 * modalidade escolhida. Os números vêm de atributos renderizados pelo servidor
 * (`data-balance-cents`, `data-minimum-minutes` e `data-price-cents`). Sem saldo
 * suficiente o envio é interrompido e o modal `[data-balance-modal]` mostra o
 * mínimo exigido, o saldo atual e o caminho para comprar créditos. Recusar de
 * vez a solicitação sem crédito continua sendo decisão do servidor, que barra o
 * pedido antes de ele chegar ao atendente.
 */
import { Modal } from 'bootstrap';

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

function setNotice(form, message) {
    const notice = form.querySelector('[data-modality-notice]');

    if (!notice) {
        return;
    }

    notice.textContent = message ?? '';
    notice.classList.toggle('d-none', !message);
}

/**
 * Saldo do cliente e mínimo exigido (em centavos) para a modalidade marcada.
 *
 * Devolve null quando falta algum dado do servidor — nesse caso o aviso é
 * apenas escondido, e a validação do servidor decide.
 *
 * @returns {{ balance: number, minimum: number } | null}
 */
function selectedMinimumCents(form) {
    const balance = Number(form.dataset.balanceCents ?? '');
    const minutes = Number(form.dataset.minimumMinutes ?? '');
    const selected = form.querySelector('input[name="modality"]:checked');
    const price = Number(selected?.dataset.priceCents ?? '');

    if (!Number.isFinite(balance) || !Number.isFinite(minutes) || !Number.isFinite(price) || minutes < 1 || price < 1) {
        return null;
    }

    return { balance, minimum: price * minutes };
}

/**
 * Rótulo "mínimo para iniciar" acompanha a modalidade selecionada.
 */
function syncMinimumLabel(form) {
    const label = form.querySelector('[data-balance-minimum-label]');
    const requirement = selectedMinimumCents(form);

    if (label && requirement) {
        label.textContent = currency.format(requirement.minimum / 100);
    }
}

/**
 * Nome visível da modalidade marcada, usado no texto do modal.
 */
function selectedModalityLabel(selected) {
    return selected.closest('.modality-option')?.querySelector('.modality-option__title')?.textContent?.trim() ?? null;
}

/**
 * Preenche e abre o modal de saldo insuficiente.
 *
 * Devolve false quando a página não tem o modal; nesse caso quem chamou cai no
 * aviso em linha do próprio formulário.
 */
function showBalanceModal(selected, requirement) {
    const modal = document.querySelector('[data-balance-modal]');

    if (!modal) {
        return false;
    }

    const modality = selectedModalityLabel(selected);

    if (modality) {
        modal.querySelector('[data-balance-modal-modality]')?.replaceChildren(modality);
    }

    modal.querySelector('[data-balance-modal-minimum]')?.replaceChildren(currency.format(requirement.minimum / 100));
    modal.querySelector('[data-balance-modal-current]')?.replaceChildren(currency.format(requirement.balance / 100));

    Modal.getOrCreateInstance(modal).show();

    return true;
}

async function ensureMedia(modality) {
    if (modality === 'text') {
        return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Seu navegador não oferece captura de áudio/vídeo. Escolha o atendimento por texto.');
    }

    const constraints = { audio: true, video: modality === 'video' };

    const stream = await navigator.mediaDevices.getUserMedia(constraints);

    stream.getTracks().forEach((track) => track.stop());
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-modality-form]').forEach((form) => {
        const button = form.querySelector('[data-modality-submit]');
        let submitting = false;

        syncMinimumLabel(form);

        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.name === 'modality') {
                syncMinimumLabel(form);
            }
        });

        form.addEventListener('submit', async (event) => {
            if (submitting) {
                return;
            }

            const selected = form.querySelector('input[name="modality"]:checked');

            if (!selected) {
                event.preventDefault();
                setNotice(form, 'Escolha uma modalidade para continuar.');

                return;
            }

            /*
             * Saldo abaixo do mínimo da modalidade: nada é enviado ao servidor.
             * O modal mostra o mínimo exigido e o saldo atual, com o caminho
             * para comprar créditos. A recusa do servidor continua valendo como
             * rede de segurança para quem estiver com JavaScript desatualizado.
             */
            const requirement = selectedMinimumCents(form);

            if (requirement && requirement.balance < requirement.minimum) {
                event.preventDefault();
                setNotice(form, null);

                if (!showBalanceModal(selected, requirement)) {
                    setNotice(form, 'Saldo insuficiente para esta modalidade. Compre créditos para continuar.');
                }

                return;
            }

            if (selected.value === 'text') {
                return;
            }

            event.preventDefault();
            setNotice(form, 'Solicitando permissão de microfone e câmera…');

            if (button) {
                button.disabled = true;
            }

            try {
                await ensureMedia(selected.value);
                submitting = true;
                form.submit();
            } catch (error) {
                setNotice(form, error.message || 'Não foi possível acessar o microfone ou a câmera.');

                if (button) {
                    button.disabled = false;
                }
            }
        });
    });
});
