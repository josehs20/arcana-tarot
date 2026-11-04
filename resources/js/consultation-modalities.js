/**
 * Seleção de modalidade no perfil público do atendente.
 *
 * Antes de enviar a solicitação, confere se o navegador consegue capturar
 * microfone (e câmera, no vídeo). A permissão é pedida uma única vez e as
 * faixas são liberadas em seguida; quem valida preço e disponibilidade é o
 * servidor.
 */
function setNotice(form, message) {
    const notice = form.querySelector('[data-modality-notice]');

    if (!notice) {
        return;
    }

    notice.textContent = message ?? '';
    notice.classList.toggle('d-none', !message);
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
