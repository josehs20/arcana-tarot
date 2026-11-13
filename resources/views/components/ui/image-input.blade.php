{{--
    Campo de upload de imagem com otimização no navegador.

    O input carrega os metadados do perfil de saída (`ImageProfile`) em
    `data-*`: o script `resources/js/image-upload.js` usa esses valores para
    reduzir e recomprimir fotos grandes antes do envio. O servidor continua
    sendo a autoridade: valida o conteúdo real, aplica a orientação do EXIF e
    grava o arquivo no formato final.

    O texto padrão de ajuda e o slot (conteúdo extra) são exibidos abaixo do
    campo; o erro do campo usa o mesmo `name` na error bag.

    Uso:
        <x-ui.image-input
            name="image"
            :profile="\App\Support\Images\ImageProfile::TarotCard"
            :required="$hasStoredImage === false"
        >
            Envie um novo arquivo apenas se quiser substituir a imagem atual.
        </x-ui.image-input>
--}}
@props([
    'name',
    'profile',
    'id' => null,
    'required' => false,
])

@php
    $fieldId = $id ?: $name;
    $maxKb = max(1, (int) config('images.max_upload_kb', 25600));
    $maxPixels = max(1, (int) config('images.max_pixels', 40_000_000));
    $hasError = $errors->has($name);
@endphp

<input
    {{ $attributes->merge([
        'class' => 'form-control'.($hasError ? ' is-invalid' : ''),
        'type' => 'file',
        'id' => $fieldId,
        'name' => $name,
        'accept' => 'image/jpeg,image/png,image/webp',
    ]) }}
    data-image-upload
    data-image-profile="{{ $profile->value }}"
    data-image-max-bytes="{{ $maxKb * 1024 }}"
    data-image-max-width="{{ $profile->maxWidth() }}"
    data-image-max-height="{{ $profile->maxHeight() }}"
    data-image-max-pixels="{{ $maxPixels }}"
    data-image-quality="{{ $profile->quality() }}"
    data-image-format="{{ $profile->format() }}"
    @required($required)
    @if ($hasError)
        aria-invalid="true"
        aria-describedby="{{ $fieldId }}-error"
    @endif
>

<div class="form-text" data-image-upload-hint>
    JPEG, PNG ou WebP, até {{ intdiv($maxKb, 1024) }} MB. A imagem é redimensionada e otimizada automaticamente.
    {{ $slot }}
</div>

@error($name)
    <div class="invalid-feedback d-block" id="{{ $fieldId }}-error">{{ $message }}</div>
@enderror
