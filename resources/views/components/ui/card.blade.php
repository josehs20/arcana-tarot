<div {{ $attributes->merge(['class' => 'card'])->except(['classes']) }}>
    {{ $slot }}
</div>
