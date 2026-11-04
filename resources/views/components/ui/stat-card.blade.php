{{--
    Cartão de indicador do painel.

    Uso:
        <x-ui.stat-card label="Clientes" :value="42" hint="40 ativos" icon="users" />
        <x-ui.stat-card label="Consultas" :value="7" href="{{ route('admin.consultations.index') }}" />
--}}
@props([
    'label',
    'value' => null,
    'hint' => null,
    'href' => null,
    'icon' => null,
    'tone' => 'default',
])

@php
    $tag = $href ? 'a' : 'div';
    $tone = in_array($tone, ['gold', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'default';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'stat-card stat-card--'.$tone]) }}
>
    <span class="stat-card__heading">
        <span class="stat-card__label">{{ $label }}</span>
        @if ($icon)
            <span class="stat-card__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>
        @endif
    </span>

    @if ($value !== null)
        <span class="stat-card__value">{{ $value }}</span>
    @endif

    {{ $slot }}

    @if ($hint)
        <span class="stat-card__hint">{{ $hint }}</span>
    @endif

    @if ($href)
        <span class="stat-card__cta">Ver detalhes <x-ui.icon name="arrow-right" width="14" height="14" /></span>
    @endif
</{{ $tag }}>
