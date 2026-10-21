<div {{ $attributes->merge(['class' => 'card-header'])->except(['classes']) }}>
    {{ $slot }}
</div>
