@props([
    'icon' => null,
    'title' => null,
    'text' => null,
])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    @if ($icon)
        <span class="empty-state-icon" aria-hidden="true">{{ $icon }}</span>
    @endif

    @if ($title)
        <h3 class="empty-state-title">{{ $title }}</h3>
    @endif

    @if ($text)
        <p class="empty-state-text">{{ $text }}</p>
    @endif

    {{ $slot }}
</div>

