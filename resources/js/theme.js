/**
 * Alternância entre os temas claro e escuro.
 *
 * O tema inicial é renderizado pelo servidor em `<html data-theme>` e
 * recuperado do localStorage pelo script inline dos layouts (antes da
 * primeira pintura). Aqui cuidamos da troca: estado visual, persistência e
 * atributos de acessibilidade.
 */
const STORAGE_KEY = 'arcana.theme';
const root = document.documentElement;
const isPersisted = root.dataset.themeUser === '1';
const endpoint = document.querySelector('meta[name="theme-endpoint"]')?.content;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

const currentTheme = () => (root.dataset.theme === 'dark' ? 'dark' : 'light');

const syncToggle = (button) => {
    const theme = currentTheme();
    const nextLabel = theme === 'dark' ? 'Ativar modo claro' : 'Ativar modo escuro';

    button.dataset.themeCurrent = theme;
    button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    button.setAttribute('aria-label', nextLabel);
    button.setAttribute('title', nextLabel);
};

const applyTheme = (theme) => {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;

    document.querySelectorAll('[data-theme-toggle]').forEach(syncToggle);
};

const storeLocally = (theme) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, theme);
    } catch (error) {
        // Armazenamento indisponível (modo privado): vale só nesta página.
    }
};

const persistTheme = async (theme) => {
    if (!isPersisted) {
        storeLocally(theme);

        return;
    }

    if (!endpoint) {
        return;
    }

    const response = await fetch(endpoint, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken ?? '',
        },
        body: JSON.stringify({ theme }),
    });

    if (!response.ok) {
        throw new Error(`Falha ao salvar a preferência de tema (${response.status}).`);
    }
};

const toggleTheme = async (button) => {
    const previous = currentTheme();
    const next = previous === 'dark' ? 'light' : 'dark';

    applyTheme(next);
    button.disabled = true;

    try {
        await persistTheme(next);
    } catch (error) {
        // Reverte a interface para não divergir do valor persistido.
        applyTheme(previous);
        console.warn(error);
    } finally {
        button.disabled = false;
    }
};

document.querySelectorAll('[data-theme-toggle]').forEach(syncToggle);

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-theme-toggle]');

    if (!button) {
        return;
    }

    event.preventDefault();
    void toggleTheme(button);
});

// Mantém abas abertas sincronizadas quando o tema muda em outra aba.
window.addEventListener('storage', (event) => {
    if (event.key !== STORAGE_KEY || isPersisted) {
        return;
    }

    if (event.newValue === 'light' || event.newValue === 'dark') {
        applyTheme(event.newValue);
    }
});
