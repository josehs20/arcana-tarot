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
    'title' => null,
])

@inject('siteContent', 'App\Support\SiteContent')

@php
    $isAdmin = $variant === 'admin';
    // O sufixo com o nome do site fica no layout: as telas passam apenas o
    // título da página e o CMS continua sendo a fonte única da identidade.
    $siteName = $siteContent->name();
    $pageTitle = filled($title) ? $title.' | '.$siteName : 'Painel | '.$siteName;
@endphp

<!DOCTYPE html>
<html lang="pt-BR" data-theme="{{ \App\Support\Theme::current()->value }}" data-theme-user="{{ \App\Support\Theme::isPersisted() ? '1' : '0' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Modo de entrega em tempo real decidido no servidor
        (`App\Support\Realtime\RealtimeMode`). `resources/js/echo.js` lê este
        marcador e não abre WebSocket quando o modo é `poll` — é o que impede o
        navegador de tentar `wss://host:8080` em hospedagem sem Reverb.
    --}}
    <meta name="realtime-mode" content="{{ \App\Support\Realtime\RealtimeMode::current() }}">
    <meta name="theme-endpoint" content="{{ route('preferences.theme.update') }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $pageTitle }}</title>

    @include('layouts.partials.theme-bootstrap')

    @vite(['resources/css/app.scss', 'resources/js/app.js'])

    @include('layouts.partials.appearance')

    {{-- Aplica o estado da sidebar antes da primeira pintura para não piscar aberta. --}}
    <script>
        try {
            if (window.localStorage.getItem('arcana.panel.sidebar') === 'collapsed') {
                document.documentElement.dataset.panelSidebar = 'collapsed';
            }
        } catch (error) {
            // Navegadores com armazenamento bloqueado mantêm o estado padrão (aberta).
        }
    </script>

    <link rel="icon" type="image/svg+xml" href="{{ $siteContent->faviconUrl() }}">
</head>
<body class="panel-body">
    <a class="visually-hidden-focusable" href="#conteudo-principal">Pular para o conteúdo</a>

    @if ($isAdmin)
        @include('layouts.partials.navbar-admin')
    @else
        @include('layouts.partials.navbar-auth')
    @endif

    <div class="panel-shell">
        @if ($isAdmin)
            @include('layouts.partials.sidebar-admin')
        @else
            @include('layouts.partials.sidebar-client')
        @endif

        <main id="conteudo-principal" class="panel-content" tabindex="-1">
            <div class="panel-content__inner">
                @include('layouts.partials.flash-messages', ['container' => 'flash-region'])
                {{ $slot }}
            </div>
        </main>
    </div>

    {{--
        Avisos em tempo real do `user.{id}`: modal de aceite/recusa e toque de
        chamada do atendente, navegação automática do cliente para a tela de
        atendimento. Fica no layout para valer em qualquer tela do painel, não só
        no dashboard.
    --}}
    @unless ($isAdmin)
        @include('layouts.partials.realtime-alerts')
    @endunless

    @stack('scripts')
</body>
</html>

