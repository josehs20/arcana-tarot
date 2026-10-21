<div {{ $attributes->merge(['class' => 'card-body'])->except(['classes']) }}>
    {{ $slot }}
</div>
