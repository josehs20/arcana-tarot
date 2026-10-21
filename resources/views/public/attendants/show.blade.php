@php
    $status = $attendant->publicStatus();
    $photoUrl = $attendant->photoUrl();
    $specialties = $attendant->specialties ?? [];
    $description = $attendant->bio
        ? Str::limit($attendant->bio, 150)
        : 'Conheça o perfil de ' . $attendant->display_name . ' no Arcana Tarot.';
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('login');
@endphp

<x-layouts.public
    :title="$attendant->display_name . ' — Atendente | Arcana Tarot'"
    :description="$description"
>
    <section class="arcana-hero arcana-hero--compact arcana-stars">
        <div class="container-wide">
            <nav aria-label="Trilha de navegação">
                <ol class="breadcrumb-arcana">
                    <li><a href="{{ route('home') }}">Início</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('attendants.index') }}">Atendentes</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page">{{ $attendant->display_name }}</li>
                </ol>
            </nav>

            <p class="arcana-eyebrow mb-2">Perfil público</p>
            <h1 class="arcana-hero__title">{{ $attendant->display_name }}</h1>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row g-4 g-lg-5">
                <div class="col-lg-8">
                    <x-ui.card class="card-gilded">
                        <x-ui.card-body class="p-4 p-md-5">
                            <div class="profile-header">
                                <div class="profile-avatar">
                                    @if ($photoUrl)
                                        <img src="{{ $photoUrl }}" alt="Foto de {{ $attendant->display_name }}" width="112" height="112">
                                    @else
                                        <span class="profile-avatar__initials" aria-hidden="true">{{ $attendant->initials() }}</span>
                                    @endif
                                </div>

                                <div>
                                    <p class="arcana-eyebrow mb-2">Atendente de tarô</p>
                                    <p class="profile-name mb-2">{{ $attendant->display_name }}</p>
                                    <x-ui.status-badge
                                        :status="$status"
                                        :label="$status === 'busy' ? 'Em consulta' : null"
                                    />
                                </div>
                            </div>

                            <hr class="profile-divider">

                            <h2 class="h4 mb-3">Apresentação</h2>
                            <p class="profile-bio mb-0">
                                {{ $attendant->bio ?: 'Este atendente ainda não publicou uma apresentação.' }}
                            </p>

                            <h2 class="h5 mt-4 mb-3">Especialidades</h2>
                            @if ($specialties !== [])
                                <ul class="profile-specialties list-unstyled mb-0" aria-label="Especialidades">
                                    @foreach ($specialties as $specialty)
                                        <li><x-ui.badge variant="gold">{{ $specialty }}</x-ui.badge></li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-ivory-dim mb-0">Especialidades não informadas.</p>
                            @endif
                        </x-ui.card-body>
                    </x-ui.card>
                </div>

                <aside class="col-lg-4">
                    <x-ui.card class="card-gilded">
                        <x-ui.card-header>
                            <h2 class="h6 mb-0">Consulta</h2>
                        </x-ui.card-header>
                        <x-ui.card-body>
                            <p class="profile-price-label">Valor por minuto</p>
                            <p class="profile-price">
                                @if ($pricePerMinute)
                                    {{ $pricePerMinute }} <span>/ minuto</span>
                                @else
                                    <span class="profile-price__unknown">Valor sob consulta</span>
                                @endif
                            </p>
                            <p class="small text-ivory-dim">
                                O valor é calculado pelo servidor e confirmado antes de
                                iniciar a consulta.
                            </p>

                            <hr class="profile-divider">

                            <p class="profile-status-hint mb-3">
                                @if ($status === 'online')
                                    Disponível para novas solicitações.
                                @elseif ($status === 'busy')
                                    Em consulta neste momento.
                                @else
                                    Não está recebendo novas solicitações agora.
                                @endif
                            </p>

                            @if ($status === 'online')
                                @guest
                                    <a class="btn btn-primary w-100" href="{{ route('login') }}">Entrar para solicitar</a>
                                    <a class="btn btn-ghost w-100 mt-2" href="{{ route('register') }}">Criar conta</a>
                                    <p class="small text-ivory-dim mt-3 mb-0">
                                        Você precisa estar autenticado para solicitar uma consulta.
                                    </p>
                                @else
                                    <a class="btn btn-primary w-100" href="{{ $panelRoute }}">Ir para o meu painel</a>
                                    <p class="small text-ivory-dim mt-3 mb-0">
                                        A solicitação de consulta é feita pelo painel, com saldo e
                                        status visíveis.
                                    </p>
                                @endguest
                            @else
                                <a class="btn btn-primary w-100" href="{{ route('attendants.index') }}">Ver outros atendentes</a>
                                <p class="small text-ivory-dim mt-3 mb-0">
                                    Você pode conhecer o perfil, mas a solicitação só é liberada
                                    quando o atendente está online.
                                </p>
                            @endif
                        </x-ui.card-body>
                    </x-ui.card>

                    <a class="btn btn-ghost w-100 mt-3" href="{{ route('attendants.index') }}">
                        Voltar ao diretório
                    </a>
                </aside>
            </div>
        </div>
    </section>
</x-layouts.public>
