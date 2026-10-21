@php
    // Atendentes e clientes compartilham este layout; cada papel tem seu painel.
    $panelRoute = auth()->user()->isAttendant()
        ? route('attendant.dashboard')
        : route('client.dashboard');
@endphp

<nav class="navbar navbar-arcana navbar-expand-lg" aria-label="Navegação do painel">
    <div class="container-fluid">
        {{-- Brand --}}
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ $panelRoute }}" aria-label="Arcana Tarot - Painel">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                <path d="M12 6v6l4 2"/>
            </svg>
            <span class="d-none d-md-inline">Painel</span>
        </a>

        {{-- Mobile sidebar toggler --}}
        <button class="btn btn-ghost d-lg-none ms-auto" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarClient" aria-controls="sidebarClient" aria-label="Abrir menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 12h18M3 6h18M3 18h18"/>
            </svg>
        </button>

        {{-- User menu (desktop) --}}
        <div class="d-none d-lg-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="btn btn-ghost d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="fw-medium">{{ Str::limit(auth()->user()->name, 20) }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ $panelRoute }}"><svg class="me-2" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg> Painel</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger"><svg class="me-2" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sair</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

