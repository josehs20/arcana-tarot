<?php

/**
 * Aparência configurável do site (cores e fontes).
 *
 * O administrador edita em "Conteúdo do site → Cores e fontes". O banco
 * guarda apenas os valores escolhidos no setting JSON `appearance`; enquanto
 * um token não for sobrescrito, o design original de `resources/css/_theme.scss`
 * continua valendo. Isso mantém a paleta e a tipografia atuais como padrão.
 *
 * As cores expostas são apenas pontos de entrada: as variantes (clara/escura,
 * `-rgb`, `bg-soft`, `surface-2`) são derivadas no servidor por
 * `App\Support\Appearance`, porque o CSS usa `rgba(var(--arcana-*-rgb), …)` em
 * vários componentes e precisa dos canais RGB coerentes com a cor-base.
 */
return [
    // Chave do setting JSON (tabela `settings`).
    'settings_key' => 'appearance',

    // Tokens de cor editáveis e seus valores padrão (idênticos a _theme.scss).
    // As chaves expostas no formulário são: gold, purple, bg e surface.
    'defaults' => [
        'light' => [
            'gold' => '#8a6a1c',
            'purple' => '#4a2d85',
            'bg' => '#f7f3e8',
            'surface' => '#fffdf8',
        ],
        'dark' => [
            'gold' => '#c9a84c',
            'purple' => '#2f1b57',
            'bg' => '#0a0918',
            'surface' => '#171433',
        ],
    ],

    // Rótulos exibidos no formulário administrativo.
    'color_labels' => [
        'gold' => 'Cor de destaque (dourado)',
        'purple' => 'Cor secundária (roxo)',
        'bg' => 'Fundo da página',
        'surface' => 'Superfície dos cards',
    ],

    // Fontes disponíveis. Apenas famílias empacotadas localmente (@fontsource)
    // ou fontes de sistema são oferecidas — sem CDN nem dependências novas.
    // A chave é o valor salvo; `stack` é o valor CSS aplicado.
    'fonts' => [
        'display' => [
            'cormorant' => [
                'label' => 'Cormorant Garamond (padrão)',
                'stack' => "'Cormorant Garamond', 'Cinzel', 'Times New Roman', serif",
            ],
            'georgia' => [
                'label' => 'Georgia (sistema)',
                'stack' => "Georgia, 'Times New Roman', serif",
            ],
            'system-sans' => [
                'label' => 'Sans do sistema',
                'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            ],
        ],
        'body' => [
            'inter' => [
                'label' => 'Inter (padrão)',
                'stack' => "'Inter', 'Manrope', -apple-system, 'Segoe UI', Roboto, sans-serif",
            ],
            'system-sans' => [
                'label' => 'Sans do sistema',
                'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif",
            ],
        ],
    ],

    // Chave de fonte considerada padrão (não gera override quando escolhida).
    'default_fonts' => [
        'display' => 'cormorant',
        'body' => 'inter',
    ],
];
