@inject('siteContent', 'App\Support\SiteContent')

@php
    $brandLogo = $siteContent->logoUrl();
@endphp

<nav class="navbar navbar-arcana navbar-expand-lg" aria-label="Navegação principal">
    <div class="container-wide">
        {{-- Brand --}}
        <a class="navbar-brand" href="{{ route('home') }}" aria-label="{{ $siteContent->name() }} - Início">
            @if ($brandLogo)
                <img class="navbar-brand__logo me-2" src="{{ $brandLogo }}" alt="" width="30" height="30">
            @else
                <svg class="me-2" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
            @endif
            <span>{{ $siteContent->name() }}</span>
        </a>

        {{-- Mobile toggler --}}
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarPublic" aria-controls="navbarPublic" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        {{-- Links editáveis no painel administrativo --}}
        <div class="collapse navbar-collapse" id="navbarPublic">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                @foreach ($siteContent->navigation('primary') as $link)
                    @php
                        $routeName = $link['route_name'] ?? null;
                        $isFragment = str_contains($link['url'], '#');
                        // Links de âncora não recebem estado ativo: o destino
                        // é uma seção da home, não uma página própria.
                        $isCurrent = ! $isFragment
                            && $routeName !== null
                            && request()->routeIs($routeName);
                    @endphp
                    <li class="nav-item">
                        <a class="nav-link {{ $isCurrent ? 'active' : '' }}"
                           href="{{ $link['url'] }}"
                           @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif
                           @if ($isCurrent) aria-current="page" @endif>{{ $link['label'] }}</a>
                    </li>
                @endforeach
            </ul>

            {{-- Ações (entrar / cadastrar / conta) --}}
            <div class="navbar-arcana__actions d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2 mt-3 mt-lg-0">
                <x-ui.theme-toggle class="navbar-arcana__theme" />

                @guest
                    <a class="btn btn-ghost btn-sm" href="{{ route('login') }}">Entrar</a>
                    <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Cadastrar</a>
                @else
                    @php
                        $roleLabel = auth()->user()->isAdmin()
                            ? 'Administração'
                            : (auth()->user()->isAttendant() ? 'Atendente' : 'Cliente');
                    @endphp
                    <div class="dropdown">
                        <button class="btn btn-ghost btn-sm dropdown-toggle d-flex align-items-center gap-2 w-100 justify-content-between justify-content-lg-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="me-1">{{ auth()->user()->name }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">{{ $roleLabel }}</h6></li>
                            @if (auth()->user()->isAttendant())
                                <li><a class="dropdown-item" href="{{ route('attendant.dashboard') }}">Painel do atendente</a></li>
                            @elseif (auth()->user()->isAdmin())
                                <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">Painel administrativo</a></li>
                            @else
                                <li><a class="dropdown-item" href="{{ route('client.dashboard') }}">Meu painel</a></li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">Sair</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                @endguest
            </div>
        </div>
    </div>
</nav>
