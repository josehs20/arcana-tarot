{{--
    Layout público (site institucional).

    Uso:
        <x-layouts.public title="..." description="...">
            ...conteúdo...
        </x-layouts.public>
--}}
@props([
    'title' => null,
    'description' => null,
])

@php
    $site = app(\App\Support\SiteContent::class);
    $pageTitle = $title ?? $site->name().' — Consultas de Tarô Online';
    $pageDescription = $description ?? $site->description();
    $favicon = $site->faviconUrl();
    $faviconType = str_ends_with((string) parse_url($favicon, PHP_URL_PATH), '.svg') ? 'image/svg+xml' : 'image/png';
@endphp

<!DOCTYPE html>
<html lang="pt-BR" data-theme="{{ \App\Support\Theme::current()->value }}" data-theme-user="{{ \App\Support\Theme::isPersisted() ? '1' : '0' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-endpoint" content="{{ route('preferences.theme.update') }}">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site->name() }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">

    @include('layouts.partials.theme-bootstrap')

    @vite(['resources/css/app.scss', 'resources/js/app.js'])

    <link rel="icon" type="{{ $faviconType }}" href="{{ $favicon }}">
</head>
<body class="bg-body d-flex flex-column min-vh-100">
    <a class="visually-hidden-focusable" href="#conteudo-principal">Pular para o conteúdo</a>

    @include('layouts.partials.navbar-public')

    <main id="conteudo-principal" class="flex-grow-1">
        {{-- Os formulários públicos exibem os erros de validação junto aos campos. --}}
        @include('layouts.partials.flash-messages', ['container' => 'container-wide pt-4', 'showErrors' => false])
        {{ $slot }}
    </main>

    @include('layouts.partials.footer-public')

    @stack('scripts')
</body>
</html>
