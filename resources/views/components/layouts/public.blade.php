{{--
    Layout público (site institucional).

    Uso:
        <x-layouts.public title="..." description="...">
            ...conteúdo...
        </x-layouts.public>
--}}
@props([
    'title' => null,
    'description' => 'Consultas de tarô online com atendentes especializados. Agende sua leitura.',
])

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Arcana Tarot — Consultas de Tarô Online' }}</title>
    <meta name="description" content="{{ $description }}">

    @vite(['resources/css/app.scss', 'resources/js/app.js'])

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
</head>
<body class="bg-body d-flex flex-column min-vh-100">
    <a class="visually-hidden-focusable" href="#conteudo-principal">Pular para o conteúdo</a>

    @include('layouts.partials.navbar-public')

    <main id="conteudo-principal" class="flex-grow-1">
        {{ $slot }}
    </main>

    @include('layouts.partials.footer-public')

    @stack('scripts')
</body>
</html>
