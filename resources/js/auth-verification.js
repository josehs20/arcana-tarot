/**
 * Cadastro com confirmação de celular.
 *
 * Três comportamentos, todos progressivos: sem JS o formulário continua
 * funcionando (o servidor normaliza o telefone e o código é validado no envio).
 *
 * - `[data-phone-mask]`: formata o número brasileiro enquanto o cliente digita;
 * - `[data-code-input]`: mantém apenas dígitos no campo do código;
 * - `[data-resend-button]`: trava o reenvio pelo tempo restante informado pelo
 *   servidor (cooldown do provedor), liberando com o rótulo original.
 */

function onlyDigits(value) {
    return value.replace(/\D+/g, '');
}

function formatPhone(digits) {
    const trimmed = digits.slice(0, 11);

    if (trimmed.length === 0) {
        return '';
    }

    if (trimmed.length <= 2) {
        return `(${trimmed}`;
    }

    const areaCode = trimmed.slice(0, 2);
    const subscriber = trimmed.slice(2);

    if (subscriber.length <= 4) {
        return `(${areaCode}) ${subscriber}`;
    }

    // Números de 10 dígitos (fixo) têm 4 no prefixo; celulares, 5.
    const prefixLength = trimmed.length > 10 ? 5 : 4;

    return `(${areaCode}) ${subscriber.slice(0, prefixLength)}-${subscriber.slice(prefixLength)}`;
}

function applyPhoneMask(input) {
    const formatted = formatPhone(onlyDigits(input.value));

    if (input.value !== formatted) {
        input.value = formatted;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-phone-mask]').forEach(applyPhoneMask);
});

document.addEventListener('input', (event) => {
    if (event.target.matches('[data-phone-mask]')) {
        applyPhoneMask(event.target);

        return;
    }

    if (event.target.matches('[data-code-input]')) {
        const digits = onlyDigits(event.target.value).slice(0, Number(event.target.dataset.codeLength || 6));

        if (event.target.value !== digits) {
            event.target.value = digits;
        }
    }
});

/**
 * Contagem regressiva do botão de reenvio. O botão fica desabilitado até zerar;
 * o servidor revalida o cooldown de qualquer forma, então um JS desatualizado
 * não permite burlar o intervalo mínimo entre SMS.
 */
document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('[data-resend-button]');

    if (!button) {
        return;
    }

    const label = button.dataset.resendLabel || button.textContent.trim();
    let remaining = Number(button.dataset.resendSeconds || 0);

    if (!Number.isFinite(remaining) || remaining < 1) {
        return;
    }

    button.disabled = true;

    const render = () => {
        button.textContent = remaining > 0
            ? `${label} em ${remaining}s`
            : label;
    };

    render();

    const timer = window.setInterval(() => {
        remaining -= 1;
        render();

        if (remaining > 0) {
            return;
        }

        window.clearInterval(timer);
        button.disabled = false;
    }, 1000);
});
