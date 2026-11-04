{{--
    Botão de alternância entre os temas claro e escuro.

    O estado inicial é renderizado pelo servidor (`data-theme` no <html>) e
    sincronizado por resources/js/theme.js. Usuários autenticados persistem a
    escolha em `users.theme_preference`; visitantes usam localStorage.
--}}
@php
    $isDark = \App\Support\Theme::isDark();
    $nextLabel = $isDark ? 'Ativar modo claro' : 'Ativar modo escuro';
@endphp

<button
    type="button"
    {{ $attributes->merge(['class' => 'theme-toggle']) }}
    data-theme-toggle
    data-theme-current="{{ $isDark ? 'dark' : 'light' }}"
    aria-pressed="{{ $isDark ? 'true' : 'false' }}"
    aria-label="{{ $nextLabel }}"
    title="{{ $nextLabel }}"
>
    <span class="theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true">
        <x-ui.icon name="moon" width="18" height="18" />
    </span>
    <span class="theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true">
        <x-ui.icon name="sun" width="18" height="18" />
    </span>
</button>
