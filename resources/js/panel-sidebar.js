/**
 * Sidebar dos painéis: recolhe/expande no desktop e lembra a escolha no navegador.
 *
 * O estado vive em <html data-panel-sidebar="collapsed">, aplicado antes da
 * primeira pintura no layout do painel; aqui apenas sincronizamos o restante.
 */
const STORAGE_KEY = 'arcana.panel.sidebar';
const COLLAPSED = 'collapsed';
const EXPANDED = 'expanded';
const TOGGLE_SELECTOR = '[data-panel-sidebar-toggle]';
const LABEL_SELECTOR = '[data-label]';
const root = document.documentElement;

const readState = () => {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch (error) {
        return null;
    }
};

const writeState = (state) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, state);
    } catch (error) {
        // Sem armazenamento disponível a preferência vale só para esta página.
    }
};

const isCollapsed = () => root.dataset.panelSidebar === COLLAPSED;

const syncLabels = () => {
    document.querySelectorAll(LABEL_SELECTOR).forEach((element) => {
        if (isCollapsed()) {
            element.setAttribute('title', element.dataset.label);
        } else {
            element.removeAttribute('title');
        }
    });
};

const syncToggles = () => {
    document.querySelectorAll(TOGGLE_SELECTOR).forEach((button) => {
        button.setAttribute('aria-expanded', String(!isCollapsed()));
        button.setAttribute('aria-label', isCollapsed() ? 'Expandir menu lateral' : 'Recolher menu lateral');
    });
};

const applyState = (collapsed) => {
    if (collapsed) {
        root.dataset.panelSidebar = COLLAPSED;
    } else {
        delete root.dataset.panelSidebar;
    }

    syncToggles();
    syncLabels();
};

if (document.querySelector(TOGGLE_SELECTOR)) {
    applyState(readState() === COLLAPSED);

    document.addEventListener('click', (event) => {
        const button = event.target.closest(TOGGLE_SELECTOR);

        if (!button) {
            return;
        }

        const collapsed = !isCollapsed();

        applyState(collapsed);
        writeState(collapsed ? COLLAPSED : EXPANDED);
    });
}
