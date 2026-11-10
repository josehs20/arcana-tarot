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

    /*
     * Cada `<option>` carrega a URL do arquivo do toque, quando existe: o MP3 da
     * plataforma (`olha-o-gas`) ou o enviado pela administração (`custom`). O
     * atributo do botão cobre o toque ativo quando o `select` não está na tela.
     */
    const selectedOptionUrl = select?.selectedOptions?.[0]?.dataset.soundUrl || null;

    previewRequestSound(sound, selectedOptionUrl || button.dataset.previewRequestSoundUrl || null);
});
