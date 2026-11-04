/**
 * Editor do catálogo de pacotes de créditos no financeiro administrativo.
 *
 * O preço de cada pacote continua sendo calculado no servidor
 * (`créditos × valor do crédito`). Aqui apenas exibimos uma prévia do total e
 * permitimos adicionar/remover linhas antes de enviar o formulário.
 */

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

function formFor(element) {
    return element?.closest('[data-credit-packages-form]') ?? null;
}

/**
 * O template das linhas fica dentro do formulário; o fallback para o elemento
 * pai evita que um template renderizado fora do <form> quebre o botão
 * "Adicionar pacote" (a falha é silenciosa por ser um listener delegado).
 */
function templateFor(form) {
    if (!form) {
        return null;
    }

    return form.querySelector('[data-package-template]') ?? form.parentElement?.querySelector('[data-package-template]') ?? null;
}

function updateRowTotal(row) {
    const form = formFor(row);
    const total = row.querySelector('[data-package-total]');
    const creditsInput = row.querySelector('[data-package-credits]');

    if (!form || !total || !creditsInput) {
        return;
    }

    const unitPriceCents = Number(form.dataset.creditUnitPriceCents || 0);
    const minimumCents = Number(form.dataset.minAmountCents || 0);
    const maximumCents = Number(form.dataset.maxAmountCents || 0);
    const credits = Number(creditsInput.value);

    if (!unitPriceCents || !Number.isFinite(credits) || credits < 1) {
        total.textContent = '—';
        total.classList.remove('text-danger');

        return;
    }

    const amountCents = credits * unitPriceCents;

    total.textContent = currency.format(amountCents / 100);
    total.classList.toggle(
        'text-danger',
        minimumCents > 0 && (amountCents < minimumCents || amountCents > maximumCents),
    );
}

function nextIndex(form) {
    const next = Number(form.dataset.nextPackageIndex || 0);

    form.dataset.nextPackageIndex = String(next + 1);

    return next;
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-credit-packages-form]').forEach((form) => {
        form.querySelectorAll('[data-package-row]').forEach(updateRowTotal);
    });
});

document.addEventListener('input', (event) => {
    const creditsInput = event.target.closest('[data-package-credits]');

    if (!creditsInput) {
        return;
    }

    updateRowTotal(creditsInput.closest('[data-package-row]'));
});

document.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-package]');

    if (addButton) {
        const form = formFor(addButton);
        const template = templateFor(form);
        const rows = form?.querySelector('[data-package-rows]');

        if (!template || !rows) {
            return;
        }

        const markup = template.innerHTML.replaceAll('__INDEX__', String(nextIndex(form)));

        rows.insertAdjacentHTML('beforeend', markup);
        rows.lastElementChild?.querySelector('[data-package-credits]')?.focus();

        return;
    }

    const removeButton = event.target.closest('[data-remove-package]');

    if (!removeButton) {
        return;
    }

    const form = formFor(removeButton);
    const rows = form?.querySelector('[data-package-rows]');
    const template = templateFor(form);

    removeButton.closest('[data-package-row]')?.remove();

    if (rows && template && rows.querySelectorAll('[data-package-row]').length === 0) {
        rows.insertAdjacentHTML(
            'beforeend',
            template.innerHTML.replaceAll('__INDEX__', String(nextIndex(form))),
        );
    }
});
