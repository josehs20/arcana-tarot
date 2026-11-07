{{--
    Campo de seleção com label, texto de ajuda e mensagens de validação.

    `options` recebe um mapa valor => rótulo (ex.: App\Enums\Gender::options()).
    Um `placeholder` vira a opção vazia inicial, usada para "não informado" em
    campos opcionais.

    Uso:
        <x-ui.select name="gender" label="Sexo" :options="\App\Enums\Gender::options()" required />
--}}
@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => '',
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'help' => null,
    'ignoreOld' => false,
])

@php
    $label = $label ?? ucfirst(str_replace(['_', '-'], ' ', $name));
    $fieldValue = (string) ($ignoreOld ? $value : old($name, $value));
    $fieldErrors = $errors->get($name);
    $hasError = $fieldErrors !== [];
    $selectClass = trim('form-select ' . ($hasError ? 'is-invalid' : ''));
@endphp

<div class="mb-3 {{ $hasError ? 'has-error' : '' }}">
    <label class="form-label {{ $required ? 'required' : '' }}" for="{{ $name }}">{{ $label }}</label>

    <select
        name="{{ $name }}"
        @required($required)
        @disabled($disabled)
        @if ($hasError)
            aria-invalid="true"
            aria-describedby="{{ $name }}-error"
        @endif
        {{ $attributes->merge(['class' => $selectClass, 'id' => $name]) }}
    >
        @if ($placeholder !== null)
            <option value="" @selected($fieldValue === '')>{{ $placeholder }}</option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($fieldValue === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($help)
        <div class="form-text">{{ $help }}</div>
    @endif

    @if ($hasError)
        <div class="invalid-feedback d-block" id="{{ $name }}-error">
            @foreach ($fieldErrors as $error)
                {{ $error }}@if (! $loop->last)<br>@endif
            @endforeach
        </div>
    @endif
</div>
