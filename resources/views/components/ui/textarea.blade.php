{{--
    Área de texto com label, texto de ajuda e mensagens de validação.

    Uso:
        <x-ui.textarea name="message" label="Mensagem" rows="6" required />
--}}
@props([
    'name',
    'label' => null,
    'value' => '',
    'rows' => 5,
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

    <textarea
        name="{{ $name }}"
        rows="{{ $rows }}"
        @required($required)
        @disabled($disabled)
        @if ($hasError)
            aria-invalid="true"
            aria-describedby="{{ $name }}-error"
        @endif
        {{ $attributes->merge(['class' => $inputClass, 'id' => $name]) }}
    >{{ $fieldValue }}</textarea>

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
