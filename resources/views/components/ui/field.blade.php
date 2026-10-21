{{--
    Campo de formulário com label, texto de ajuda e mensagens de validação.

    Os atributos não declarados (autocomplete, autofocus, inputmode,
    maxlength, readonly, ...) são repassados diretamente ao <input>.

    Uso:
        <x-ui.field name="email" label="E-mail" type="email" autocomplete="email" required />
--}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => '',
    'placeholder' => '',
    'required' => false,
    'disabled' => false,
    'help' => null,
    'ignoreOld' => false,
])

@php
    $label = $label ?? ucfirst(str_replace(['_', '-'], ' ', $name));
    $fieldValue = $ignoreOld ? $value : old($name, $value);
    $fieldErrors = $errors->get($name);
    $hasError = $fieldErrors !== [];
    $inputClass = trim('form-control ' . ($hasError ? 'is-invalid' : ''));
@endphp

<div class="mb-3 {{ $hasError ? 'has-error' : '' }}">
    <label class="form-label {{ $required ? 'required' : '' }}" for="{{ $name }}">{{ $label }}</label>

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        value="{{ $fieldValue }}"
        placeholder="{{ $placeholder }}"
        @required($required)
        @disabled($disabled)
        @if ($hasError)
            aria-invalid="true"
            aria-describedby="{{ $name }}-error"
        @endif
        {{ $attributes->merge(['class' => $inputClass, 'id' => $name]) }}
    >

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

