<nav class="navbar navbar-arcana navbar-expand-lg" aria-label="Navegação principal">
    <div class="container-wide">
        {{-- Brand --}}
        <a class="navbar-brand" href="/" aria-label="Arcana Tarot - Início">
            <svg class="me-2" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                <path d="M12 6v6l4 2"/>
            </svg>
            <span>Arcana Tarot</span>
        </a>

        {{-- Mobile toggler --}}
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarPublic" aria-controls="navbarPublic" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        {{-- Links --}}
        <div class="collapse navbar-collapse" id="navbarPublic">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="/">Início</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#como-funciona">Como funciona</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#atendentes">Atendentes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#faq">Dúvidas</a>
                </li>
            </ul>

            {{-- Ações (entrar / cadastrar) --}}
            <div class="d-flex align-items-center gap-2">
                @guest
                    <a class="btn btn-ghost btn-sm" href="{{ route('login') }}">Entrar</a>
                    <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Cadastrar</a>
                @else
                    <div class="dropdown">
                        <button class="btn btn-ghost btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="me-1">{{ auth()->user()->name }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if (auth()->user()->isAttendant())
                                <li><a class="dropdown-item" href="{{ route('attendant.dashboard') }}">Painel Atendente</a></li>
                            @elseif (auth()->user()->isAdmin())
                                <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">Painel Admin</a></li>
                            @else
                                <li><a class="dropdown-item" href="{{ route('client.dashboard') }}">Meu Painel</a></li>
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
