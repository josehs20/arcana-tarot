@php
    // O mesmo layout atende clientes e atendentes; o painel muda conforme o papel.
    $panelRoute = auth()->user()->isAttendant()
        ? route('attendant.dashboard')
        : route('client.dashboard');

    $isDashboard = request()->routeIs('client.dashboard', 'attendant.dashboard');
@endphp

<aside class="offcanvas offcanvas-start offcanvas-sidebar bg-card border-end" tabindex="-1" id="sidebarClient" aria-label="Menu lateral">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-semibold">Menu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body p-0">
        <nav class="nav flex-column px-3 py-3" aria-label="Navegação do painel">
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded {{ $isDashboard ? 'active bg-deep-purple' : '' }}"
               href="{{ $panelRoute }}"
               @if ($isDashboard) aria-current="page" @endif>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Painel</span>
            </a>
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded" href="{{ route('home') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <span>Site público</span>
            </a>
            <hr class="my-3" style="border-color: rgba(201, 168, 76, 0.15);">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded text-danger w-100 text-start" style="background: none; border: none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Sair</span>
                </button>
            </form>
        </nav>
    </div>
</aside>

