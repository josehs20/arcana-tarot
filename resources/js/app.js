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

