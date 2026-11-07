{{--
    Sidebar compartilhada pelos painéis. Estrutura única para admin,
    atendente e cliente; os grupos de navegação vêm da partial do layout.
--}}
@props([
    'id',
    'groups' => [],
    'label' => 'Menu lateral',
])

@inject('siteContent', 'App\Support\SiteContent')

@php
    $user = auth()->user();
    $roleLabel = $user->isAdmin() ? 'Administração' : ($user->isAttendant() ? 'Atendente' : 'Cliente');
    $initials = collect(preg_split('/\s+/', trim($user->name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<aside
    class="offcanvas offcanvas-start offcanvas-sidebar panel-sidebar"
    tabindex="-1"
    id="{{ $id }}"
    aria-label="{{ $label }}"
>
    <div class="panel-sidebar__header">
        <a class="panel-sidebar__brand" href="{{ route('home') }}" aria-label="{{ $siteContent->name() }} — início">
            @if ($siteContent->logoUrl())
                <img src="{{ $siteContent->logoUrl() }}" alt="" width="34" height="34" class="panel-sidebar__logo">
            @else
                <span class="panel-sidebar__mark" aria-hidden="true"><x-ui.icon name="sparkles" width="22" height="22" /></span>
            @endif
            <span class="panel-sidebar__brand-text">
                <span class="panel-sidebar__name">{{ $siteContent->name() }}</span>
                <span class="panel-sidebar__area">{{ $roleLabel }}</span>
            </span>
        </a>

        {{-- Recolhe/expande a coluna no desktop; o estado é lembrado no navegador. --}}
        <button
            type="button"
            class="panel-sidebar__collapse d-none d-lg-inline-flex"
            data-panel-sidebar-toggle
            aria-controls="{{ $id }}"
            aria-expanded="true"
            aria-label="Recolher menu lateral"
        >
            <x-ui.icon name="chevrons-left" width="16" height="16" />
        </button>

        <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" aria-label="Fechar menu"></button>
    </div>

    <div class="panel-sidebar__user">
        <span class="panel-sidebar__avatar" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
        <span class="panel-sidebar__identity">
            <span class="panel-sidebar__user-name">{{ $user->name }}</span>
            <span class="panel-sidebar__user-email">{{ $user->email }}</span>
        </span>
    </div>

    <div class="panel-sidebar__body">
        @foreach ($groups as $group)
            <nav class="panel-nav" aria-label="{{ $group['label'] }}">
                <p class="panel-nav__label">{{ $group['label'] }}</p>

                @foreach ($group['links'] as $link)
                    @php $isActive = request()->routeIs($link['pattern']); @endphp
                    <a
                        class="panel-nav__link {{ $isActive ? 'is-active' : '' }}"
                        href="{{ route($link['route']) }}"
                        data-label="{{ $link['label'] }}"
                        @if ($isActive) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$link['icon'] ?? 'dot'" class="panel-nav__icon" />
                        <span class="panel-nav__text">{{ $link['label'] }}</span>
                        @if (! empty($link['badge']))
                            <span
                                class="panel-nav__badge"
                                @if (! empty($link['badge_label'])) aria-label="{{ $link['badge_label'] }}" @endif
                            >{{ $link['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        @endforeach
    </div>

    <div class="panel-sidebar__footer">
        <a class="panel-sidebar__footer-link" href="{{ route('home') }}" data-label="Ver site público">
            <x-ui.icon name="globe" class="panel-nav__icon" />
            <span>Ver site público</span>
        </a>

        {{-- O logout encerra a consulta no servidor: sair é intencional e não avisa. --}}
        <form method="POST" action="{{ route('logout') }}" data-allow-leave>
            @csrf
            <button type="submit" class="panel-sidebar__footer-link panel-sidebar__footer-link--danger" data-label="Sair da conta">
                <x-ui.icon name="log-out" class="panel-nav__icon" />
                <span>Sair da conta</span>
            </button>
        </form>
    </div>
</aside>
