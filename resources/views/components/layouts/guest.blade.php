{{--
    Layout de autenticação — cartão centralizado sobre o fundo místico.

    Uso:
        <x-layouts.guest title="Entrar">
            ...formulário...
        </x-layouts.guest>
--}}
@props([
    'title' => 'Arcana Tarot',
])

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
<body class="bg-body auth-shell arcana-stars">
    <div class="auth-card arcana-fade-up">
        <a class="auth-card__brand" href="{{ route('home') }}" aria-label="Arcana Tarot — Início">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                <path d="M12 6v6l4 2"/>
            </svg>
            <span>Arcana Tarot</span>
        </a>

        <div class="mt-4">
            {{ $slot }}
        </div>
    </div>

    @stack('scripts')
</body>
</html>
