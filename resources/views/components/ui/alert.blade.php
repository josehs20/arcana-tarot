{{--
    Alerta com ícone opcional e botão de fechar.

    Uso:
        <x-ui.alert type="success">Salvo com sucesso.</x-ui.alert>
        <x-ui.alert type="danger" :dismissible="false">...</x-ui.alert>
--}}
@props([
    'type' => 'info',
    'dismissible' => true,
    'icon' => true,
])

@php
    $icons = [
        'success' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'danger' => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'warning' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    ];

    $iconPath = $icons[$type] ?? $icons['info'];
@endphp

<div role="alert" {{ $attributes->merge(['class' => 'alert alert-' . $type . ($dismissible ? ' alert-dismissible fade show' : '')]) }}>
    @if ($icon)
        <svg class="flex-shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            {!! $iconPath !!}
        </svg>
    @endif

    {{ $slot }}

    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    @endif
</div>

