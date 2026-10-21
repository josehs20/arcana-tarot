<aside class="offcanvas offcanvas-start offcanvas-sidebar bg-card border-end" tabindex="-1" id="sidebarAdmin" aria-label="Menu lateral admin">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-semibold">Admin</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body p-0">
        <nav class="nav flex-column px-3 py-3" aria-label="Navegação admin">
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded {{ request()->routeIs('admin.dashboard') ? 'active bg-deep-purple' : '' }}" href="{{ route('admin.dashboard') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Dashboard</span>
            </a>
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded {{ request()->routeIs('admin.applications.*') ? 'active bg-deep-purple' : '' }}" href="{{ route('admin.applications.index') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Candidaturas</span>
                @if (($pendingApplicationsCount ?? 0) > 0)
                    <span class="badge bg-warning text-dark ms-auto" aria-label="{{ $pendingApplicationsCount }} {{ $pendingApplicationsCount === 1 ? 'candidatura pendente' : 'candidaturas pendentes' }}">{{ $pendingApplicationsCount }}</span>
                @endif
            </a>
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded" href="#">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Usuários</span>
            </a>
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded" href="#">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Atendentes</span>
            </a>
            <a class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded" href="#">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>Configurações</span>
            </a>
            <hr class="my-3" style="border-color: rgba(201, 168, 76, 0.15);">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-link d-flex align-items-center gap-3 px-3 py-2 rounded text-danger w-100 text-start" style="background: none; border: none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Sair</span>
                </button>
            </form>
        </nav>
    </div>
</aside>
