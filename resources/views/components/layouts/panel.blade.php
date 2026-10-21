{{--
    Layout do painel autenticado (navbar + sidebar + área de conteúdo).

    Uso:
        <x-layouts.panel variant="admin" title="Painel — Admin">
            ...conteúdo...
        </x-layouts.panel>

    Variantes: "admin" (admin.dashboard) | "panel" (client/attendant).
--}}
@props([
    'variant' => 'panel',
    'title' => 'Painel — Arcana Tarot',
])

@php
    $isAdmin = $variant === 'admin';
@endphp

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>

    @vite(['resources/css/app.scss', 'resources/js/app.js'])

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
</head>
<body class="bg-body d-flex flex-column min-vh-100">
    <a class="visually-hidden-focusable" href="#conteudo-principal">Pular para o conteúdo</a>

    @if ($isAdmin)
        @include('layouts.partials.navbar-admin')
    @else
        @include('layouts.partials.navbar-auth')
    @endif

    <div class="container-fluid flex-grow-1">
        <div class="row g-0">
            @if ($isAdmin)
                @include('layouts.partials.sidebar-admin')
            @else
                @include('layouts.partials.sidebar-client')
            @endif

            {{-- `col` sem largura fixa: a sidebar consome 260px e o conteúdo
                 ocupa o restante. `col-lg-10` causaria quebra de linha no flex. --}}
            <main id="conteudo-principal" class="col px-3 px-md-4 py-4">
                @include('layouts.partials.flash-messages')
                {{ $slot }}
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
