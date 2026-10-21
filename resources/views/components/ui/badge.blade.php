<span {{ $attributes->merge(['class' => 'badge badge-' . ($variant ?? 'secondary')])->except(['variant', 'classes']) }}>
    {{ $slot }}
</span>
