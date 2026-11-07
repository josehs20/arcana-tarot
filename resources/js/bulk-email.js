/**
 * Disparo de e-mails (/admin/emails): seleção de destinatários, contador e prévia.
 *
 * Tudo aqui é melhoria de tela: a lista final de destinatários, a validação e o
 * envio são decididos no servidor (BulkEmailDispatcher). Sem JavaScript o
 * formulário continua funcionando — apenas sem contador, prévia e confirmação.
 */

const FORM_SELECTOR = '[data-bulk-email-form]';
const RECIPIENT_SELECTOR = '[data-bulk-email-recipient]';
const SEND_SELECTOR = '[data-bulk-email-send]';

const currentForm = () => document.querySelector(FORM_SELECTOR);

const recipientInputs = () => Array.from(document.querySelectorAll(RECIPIENT_SELECTOR));

const selectedCount = () => recipientInputs().filter((input) => input.checked).length;

/**
 * Mantém contador, estado do "selecionar página" e o destaque do botão de envio.
 */
function syncSelection() {
    const form = currentForm();

    if (!form) {
        return;
    }

    const inputs = recipientInputs();
    const total = selectedCount();

    form.querySelectorAll('[data-bulk-email-count]').forEach((element) => {
        element.textContent = String(total);
    });

    const selectAll = form.querySelector('[data-bulk-email-select-all]');

    if (selectAll) {
        selectAll.checked = inputs.length > 0 && total === inputs.length;
        selectAll.indeterminate = total > 0 && total < inputs.length;
    }

    if (total > 0) {
        toggleSelectionWarning(form, false);
    }
}

/**
 * Prévia com dados de exemplo: os marcadores são substituídos pelo mesmo texto
 * que o servidor usaria, mas apenas como texto (nunca como HTML).
 */
function renderPreview() {
    const form = currentForm();
    const box = form?.querySelector('[data-bulk-email-preview]');

    if (!form || !box) {
        return;
    }

    const sampleName = form.dataset.bulkEmailSampleName || 'cliente';
    const replacements = [
        ['{{nome}}', sampleName],
        ['{{email}}', 'cliente@exemplo.com'],
        ['{{link}}', window.location.origin],
    ];

    const fill = (text) => replacements.reduce(
        (result, [placeholder, value]) => result.replaceAll(placeholder, value),
        String(text ?? ''),
    );

    const subject = form.querySelector('input[name="subject"]');
    const body = form.querySelector('textarea[name="body"]');
    const subjectTarget = box.querySelector('[data-bulk-email-preview-subject]');
    const bodyTarget = box.querySelector('[data-bulk-email-preview-body]');

    if (subjectTarget) {
        subjectTarget.textContent = fill(subject?.value).trim() || '(sem assunto)';
    }

    if (bodyTarget) {
        bodyTarget.textContent = fill(body?.value).trim() || '(corpo vazio)';
    }
}

const previewIsVisible = () => {
    const box = currentForm()?.querySelector('[data-bulk-email-preview]');

    return Boolean(box) && !box.classList.contains('d-none');
};

/**
 * Alterna entre o aviso padrão e o lembrete de que nenhum destinatário foi marcado.
 */
function toggleSelectionWarning(form, visible) {
    const fallback = form.querySelector('[data-bulk-email-hint-default]');
    const warning = form.querySelector('[data-bulk-email-hint-error]');

    if (fallback) {
        fallback.classList.toggle('d-none', visible);
    }

    if (warning) {
        warning.classList.toggle('d-none', !visible);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (!currentForm()) {
        return;
    }

    syncSelection();
});

document.addEventListener('change', (event) => {
    if (!event.target.closest(`${RECIPIENT_SELECTOR}, [data-bulk-email-select-all]`)) {
        return;
    }

    const selectAll = event.target.closest('[data-bulk-email-select-all]');

    if (selectAll) {
        recipientInputs().forEach((input) => {
            input.checked = selectAll.checked;
        });
    }

    syncSelection();
});

document.addEventListener('input', (event) => {
    if (!event.target.closest('input[name="subject"], textarea[name="body"]') || !previewIsVisible()) {
        return;
    }

    renderPreview();
});

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-bulk-email-preview-toggle]');

    if (!toggle) {
        return;
    }

    const box = currentForm()?.querySelector('[data-bulk-email-preview]');

    if (!box) {
        return;
    }

    const willShow = box.classList.contains('d-none');

    box.classList.toggle('d-none', !willShow);
    toggle.setAttribute('aria-expanded', String(willShow));

    if (willShow) {
        renderPreview();
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest(FORM_SELECTOR);

    if (!form) {
        return;
    }

    // Só o botão de envio pede confirmação; salvar o corpo padrão não altera nada.
    const submitter = event.submitter;

    if (!submitter || !submitter.matches(SEND_SELECTOR)) {
        return;
    }

    const total = selectedCount();

    if (total === 0) {
        event.preventDefault();
        toggleSelectionWarning(form, true);

        return;
    }

    const message = `Confirmar envio para ${total} destinatário(s)? Os e-mails entram na fila e não podem ser cancelados.`;

    if (!window.confirm(message)) {
        event.preventDefault();
    }
});
