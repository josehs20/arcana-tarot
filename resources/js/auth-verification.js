/**
 * Máscara do telefone no cadastro.
 *
 * Comportamento progressivo: sem JS o formulário continua funcionando, porque o
 * servidor normaliza o número em App\Support\PhoneNumber antes de validar.
 *
 * - `[data-phone-mask]`: formata o número brasileiro enquanto o cliente digita.
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
    }
});
