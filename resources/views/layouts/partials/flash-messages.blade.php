{{--
    Alertas de sessão (flash) e erros de validação.

    Uso:
        @include('layouts.partials.flash-messages')
        @include('layouts.partials.flash-messages', ['container' => 'container-wide pt-4'])

    Parâmetros opcionais:
        container  — classe do invólucro externo. Sem ele o partial emite apenas os
                     alertas (o painel já aplica o próprio espaçamento).
        showErrors — false em telas que já listam os erros de validação por conta
                     própria, para não repetir as mensagens.
--}}
@php
    $flashTypes = [
        'success' => 'success',
        'status' => 'success',
        'info' => 'info',
        'warning' => 'warning',
        'error' => 'danger',
    ];

    $flashMessages = collect(array_keys($flashTypes))
        ->mapWithKeys(fn (string $key) => [$key => session($key)])
        ->filter(fn ($message) => filled($message));

    $wrapper = $container ?? null;
    $hasValidationErrors = ($showErrors ?? true) && $errors->any();
@endphp

@if ($flashMessages->isNotEmpty() || $hasValidationErrors)
    @if ($wrapper)
        <div class="{{ $wrapper }}">
    @endif

    @foreach ($flashMessages as $key => $message)
        <x-ui.alert :type="$flashTypes[$key]" class="mb-3">{{ $message }}</x-ui.alert>
    @endforeach

    @if ($hasValidationErrors)
        <x-ui.alert type="danger" class="mb-3">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if ($wrapper)
        </div>
    @endif
@endif

