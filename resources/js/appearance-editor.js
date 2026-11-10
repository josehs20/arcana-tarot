// Pré-visualização ao vivo do editor "Cores e fontes" (/admin/conteudo/aparencia).
//
// Espelha exatamente a derivação feita em App\Support\Appearance: só emite
// overrides para os tokens que diferem do padrão do tema, garantindo fidelidade
// ao design original enquanto nada for alterado. A injeção usa a mesma
// especificidade `html[data-theme=...]` do backend, num <style> anexado ao fim
// do <head> (depois do bundle), então vence a ordem do Vite.
const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;
const PREVIEW_ID = 'arcana-appearance-preview';

function hexToRgb(hex) {
    const value = hex.replace('#', '');

    return [
        parseInt(value.slice(0, 2), 16),
        parseInt(value.slice(2, 4), 16),
        parseInt(value.slice(4, 6), 16),
    ];
}

function channelToHex(value) {
    return value.toString(16).padStart(2, '0');
}

function mix(base, toward, ratio) {
    const [br, bg, bb] = hexToRgb(base);
    const [tr, tg, tb] = hexToRgb(toward);
    const blend = (from, to) => Math.round(from + (to - from) * ratio);

    return '#' + channelToHex(blend(br, tr)) + channelToHex(blend(bg, tg)) + channelToHex(blend(bb, tb));
}

function rgbChannels(hex) {
    const [r, g, b] = hexToRgb(hex);

    return `${r}, ${g}, ${b}`;
}

function themeDeclarations(theme, colors, defaults) {
    const base = colors[theme] || {};
    const fallback = defaults[theme] || {};
    const isLight = theme === 'light';
    const declarations = [];

    if (base.gold && base.gold !== fallback.gold) {
        declarations.push(`--arcana-gold:${base.gold}`);
        declarations.push(`--arcana-gold-rgb:${rgbChannels(base.gold)}`);
        declarations.push(`--arcana-gold-light:${mix(base.gold, isLight ? '#000000' : '#ffffff', isLight ? 0.18 : 0.22)}`);
        declarations.push(`--arcana-gold-dark:${mix(base.gold, '#000000', 0.3)}`);
    }

    if (base.purple && base.purple !== fallback.purple) {
        declarations.push(`--arcana-purple:${base.purple}`);
        declarations.push(`--arcana-purple-rgb:${rgbChannels(base.purple)}`);
        declarations.push(`--arcana-purple-light:${mix(base.purple, '#ffffff', 0.18)}`);
        declarations.push(`--arcana-purple-soft:${mix(base.purple, '#ffffff', 0.32)}`);
    }

    if (base.bg && base.bg !== fallback.bg) {
        declarations.push(`--arcana-bg:${base.bg}`);
        declarations.push(`--arcana-bg-soft:${mix(base.bg, isLight ? '#000000' : '#ffffff', 0.06)}`);
    }

    if (base.surface && base.surface !== fallback.surface) {
        const surface2 = mix(base.surface, isLight ? '#000000' : '#ffffff', 0.05);
        declarations.push(`--arcana-surface:${base.surface}`);
        declarations.push(`--arcana-surface-2:${surface2}`);
        declarations.push(`--arcana-surface-2-rgb:${rgbChannels(surface2)}`);
    }

    return declarations;
}

function readConfig(root) {
    const node = root.querySelector('[data-appearance-config]');

    if (!node) {
        return { defaults: {}, defaultFonts: {} };
    }

    try {
        return JSON.parse(node.textContent) || { defaults: {}, defaultFonts: {} };
    } catch (error) {
        return { defaults: {}, defaultFonts: {} };
    }
}

function initEditor(root) {
    const config = readConfig(root);
    const colorInputs = Array.from(root.querySelectorAll('input[data-theme][data-token]'));
    const fontSelects = Array.from(root.querySelectorAll('select[data-kind]'));

    const readColors = () => {
        const colors = { light: {}, dark: {} };

        colorInputs.forEach((input) => {
            if (HEX_PATTERN.test(input.value)) {
                colors[input.dataset.theme][input.dataset.token] = input.value.toLowerCase();
            }
        });

        return colors;
    };

    const fontDeclarations = () => {
        const declarations = [];

        fontSelects.forEach((select) => {
            if (select.value === config.defaultFonts?.[select.dataset.kind]) {
                return;
            }

            const stack = select.options[select.selectedIndex]?.dataset.stack;

            if (stack) {
                declarations.push(`--arcana-font-${select.dataset.kind}:${stack}`);
            }
        });

        return declarations;
    };

    const render = () => {
        const colors = readColors();
        const rules = [];

        ['light', 'dark'].forEach((theme) => {
            const declarations = themeDeclarations(theme, colors, config.defaults || {});

            if (declarations.length > 0) {
                rules.push(`html[data-theme="${theme}"]{${declarations.join(';')}}`);
            }
        });

        const fontRules = fontDeclarations();

        if (fontRules.length > 0) {
            rules.push(`html:root{${fontRules.join(';')}}`);
        }

        let style = document.getElementById(PREVIEW_ID);

        if (!style) {
            style = document.createElement('style');
            style.id = PREVIEW_ID;
            document.head.appendChild(style);
        }

        style.textContent = rules.join('\n');

        colorInputs.forEach((input) => {
            const label = document.querySelector(`[data-hex-for="${input.id}"]`);

            if (label) {
                label.textContent = input.value;
            }
        });
    };

    let timer = null;
    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(render, 120);
    };

    colorInputs.forEach((input) => input.addEventListener('input', schedule));
    fontSelects.forEach((select) => select.addEventListener('change', schedule));

    render();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-appearance-editor]').forEach((root) => initEditor(root));
});
