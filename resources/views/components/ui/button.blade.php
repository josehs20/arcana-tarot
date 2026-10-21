@php
    $variant = $variant ?? 'primary';
    $size = $size ?? 'md';
    $block = $block ?? false;
    $loading = $loading ?? false;
    $disabled = $disabled ?? false;
    $type = $type ?? 'button';

    $mapSize = [
        'sm' => 'btn-sm',
        'md' => '',
        'lg' => 'btn-lg',
    ];
    $variantClass = 'btn-' . $variant;
    $sizeClass = $mapSize[$size] ?? '';
    $blockClass = $block ? 'w-100' : '';
    $disabledAttr = ($disabled || $loading) ? 'disabled' : '';
@endphp

<button type="{{ $type }}"
        {{ $attributes->merge(['class' => 'btn ' . $variantClass . ' ' . $sizeClass . ' ' . $blockClass])->except(['variant', 'size', 'block', 'loading', 'disabled', 'classes', 'type']) }}
        {{ $disabledAttr }}
    @if ($loading)
        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</button>
