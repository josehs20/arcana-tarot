<div {{ $attributes->merge(['class' => 'card-footer'])->except(['classes']) }}>
    {{ $slot }}
</div>
