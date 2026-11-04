/**
 * Prévia do som de chamada no cadastro administrativo de atendentes.
 *
 * Os sintetizadores e o player do MP3 ficam em `user-channel.js`, a mesma fonte
 * usada pelo painel do atendente. Aqui apenas ligamos o botão "Ouvir" ao `select`
 * correspondente (ou ao arquivo personalizado), para que a administração teste
 * o toque antes de salvar.
 */

import { previewRequestSound } from './user-channel';

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-preview-request-sound]');

    if (!button) {
        return;
    }

    const select = document.getElementById(button.dataset.previewRequestSound);
    const sound = select?.value ?? 'siren';

    previewRequestSound(sound, button.dataset.previewRequestSoundUrl || null);
});
