{{--
    Layout das páginas de erro.

    Uso:
        <x-layouts.error code="404" title="..." text="...">
            ...ações extras...
        </x-layouts.error>
--}}
@props([
    'code' => '500',
    'title' => 'Algo deu errado',
    'text' => 'Não foi possível concluir a sua solicitação. Tente novamente em instantes.',
])

@inject('siteContent', 'App\Support\SiteContent')

<!DOCTYPE html>
<html lang="pt-BR" data-theme="{{ \App\Support\Theme::current()->value }}" data-theme-user="{{ \App\Support\Theme::isPersisted() ? '1' : '0' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-endpoint" content="{{ route('preferences.theme.update') }}">
    <title>{{ $code }} — {{ $title }} | {{ $siteContent->name() }}</title>
    <meta name="robots" content="noindex">

    @include('layouts.partials.theme-bootstrap')

    @vite(['resources/css/app.scss', 'resources/js/app.js'])

    @include('layouts.partials.appearance')

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
</head>
<body class="bg-body error-page arcana-stars">
    <div class="theme-toggle-dock">
        <x-ui.theme-toggle />
    </div>

    <div class="container">
        <p class="error-page__code">{{ $code }}</p>
        <h1 class="error-page__title">{{ $title }}</h1>
        <p class="error-page__text">{{ $text }}</p>

        <div class="error-page__actions">
            <a class="btn btn-primary" href="{{ url('/') }}">Voltar ao início</a>
            {{ $slot }}
        </div>
    </div>
</body>
</html>
