@props([
    'variant' => 'secondary',
])

<span {{ $attributes->merge(['class' => 'badge badge-' . $variant])->except(['variant', 'classes']) }}>
    {{ $slot }}
</span>
