{{--
    Navbar compartilhada pelos painéis autenticados.
--}}
@props([
    'sidebarId',
    'panelRoute',
    'area' => 'Painel',
])

@inject('siteContent', 'App\Support\SiteContent')

@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim($user->name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $historyRoute = $user->isAdmin()
        ? route('admin.audit.index')
        : ($user->isAttendant() ? route('attendant.history') : route('client.history'));
@endphp

<nav class="navbar navbar-arcana panel-navbar" aria-label="Navegação principal do painel">
    <div class="container-fluid panel-navbar__inner">
        <button
            class="panel-navbar__toggle d-lg-none"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#{{ $sidebarId }}"
            aria-controls="{{ $sidebarId }}"
            aria-label="Abrir menu lateral"
        >
            <x-ui.icon name="menu" width="22" height="22" />
        </button>

        <a class="panel-navbar__brand" href="{{ $panelRoute }}" aria-label="{{ $siteContent->name() }} — {{ $area }}">
            @if ($siteContent->logoUrl())
                <img src="{{ $siteContent->logoUrl() }}" alt="" width="30" height="30" class="panel-navbar__logo">
            @else
                <span class="panel-navbar__mark" aria-hidden="true"><x-ui.icon name="sparkles" width="20" height="20" /></span>
            @endif
            <span class="panel-navbar__brand-name d-none d-sm-inline">{{ $siteContent->name() }}</span>
            <span class="panel-navbar__area">{{ $area }}</span>
        </a>

        <div class="panel-navbar__actions">
            <x-ui.theme-toggle class="panel-navbar__theme" />

            <a class="panel-navbar__site-link d-none d-md-inline-flex" href="{{ route('home') }}">
                <x-ui.icon name="external-link" width="16" height="16" />
                <span>Site público</span>
            </a>

            <div class="dropdown">
                <button class="panel-navbar__user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="panel-navbar__avatar" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
                    <span class="panel-navbar__user-name d-none d-md-inline">{{ $user->name }}</span>
                    <x-ui.icon name="chevron-down" width="14" height="14" class="panel-navbar__chevron" />
                    <span class="visually-hidden">Abrir menu da conta</span>
                </button>

                <ul class="dropdown-menu dropdown-menu-end panel-navbar__dropdown">
                    <li class="panel-navbar__dropdown-header">
                        <span class="panel-navbar__dropdown-name">{{ $user->name }}</span>
                        <span class="panel-navbar__dropdown-email">{{ $user->email }}</span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ $panelRoute }}">
                            <x-ui.icon name="dashboard" width="16" height="16" class="me-2" />
                            {{ $area }}
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ $historyRoute }}">
                            <x-ui.icon name="clock" width="16" height="16" class="me-2" />
                            {{ $user->isAdmin() ? 'Auditoria' : 'Histórico' }}
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('home') }}">
                            <x-ui.icon name="globe" width="16" height="16" class="me-2" />
                            Site público
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" data-allow-leave>
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <x-ui.icon name="log-out" width="16" height="16" class="me-2" />
                                Sair
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
