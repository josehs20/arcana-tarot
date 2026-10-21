{{--
    Cabeçalho de página do painel.

    Uso:
        <x-layouts.page-header title="Painel do Cliente" subtitle="...">
            <x-slot name="actions">...</x-slot>
        </x-layouts.page-header>
--}}
@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'page-header mb-4'])->except(['title', 'subtitle']) }}>
    <div class="page-header__text">
        @if ($title)
            <h1 class="page-header__title">{{ $title }}</h1>
        @endif
        @if ($subtitle)
            <p class="page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-header__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
