import 'bootstrap';

// Bootstrap trata navegação (navbar), dropdowns, offcanvas e alertas dispensáveis.
// Código adicional de interação será adicionado nos prompts de chat e presença.

// Confirmação nativa para ações administrativas destrutivas ou irreversíveis.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.dataset.confirm;

            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});


/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
import './chat';
import './consultation-workspace';
import './consultation-timer';
import './consultation-presence';
import './consultation-modalities';
import './consultation-call';
import './user-channel';
import './attendant-sound-preview';
import './credit-packages';
import './panel-sidebar';
import './theme';
