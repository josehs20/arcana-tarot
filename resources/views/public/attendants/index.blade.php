@php
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('login');
@endphp

<x-layouts.public
    title="Atendentes — Arcana Tarot"
    description="Conheça os atendentes de tarô do Arcana Tarot, suas especialidades, disponibilidade e valor por minuto."
>
    <section class="arcana-hero arcana-hero--compact arcana-stars">
        <div class="container-wide">
            <nav aria-label="Trilha de navegação">
                <ol class="breadcrumb-arcana">
                    <li><a href="{{ route('home') }}">Início</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page">Atendentes</li>
                </ol>
            </nav>

            <p class="arcana-eyebrow mb-2">Diretório</p>
            <h1 class="arcana-hero__title">Quem lê as suas cartas</h1>
            <div class="arcana-hero__divider" aria-hidden="true"></div>
            <p class="arcana-lead mb-0">
                Veja quem está disponível, conheça cada especialidade e escolha com quem
                conversar. Para solicitar uma consulta, entre na sua conta.
            </p>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <h2 class="visually-hidden">Lista de atendentes</h2>

            @if ($attendants->isEmpty())
                <x-ui.empty-state
                    icon="☾"
                    title="Nenhum atendente publicado ainda"
                    text="Estamos preparando o quadro de profissionais. Volte em breve ou conheça como o Arcana Tarot funciona."
                >
                    <a class="btn btn-ghost mt-2" href="{{ route('home') }}#como-funciona">Como funciona</a>
                </x-ui.empty-state>
            @else
                <div class="row g-4">
                    @foreach ($attendants as $attendant)
                        <div class="col-md-6 col-xl-4">
                            <x-public.attendant-card :attendant="$attendant" :price="$pricePerMinute" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="arcana-section arcana-section--alt">
        <div class="container-wide text-center">
            <h2 class="arcana-section__title">Prefere conversar primeiro?</h2>
            <p class="arcana-section__subtitle mx-auto mb-4">
                Crie sua conta gratuita para solicitar uma consulta quando encontrar o
                atendente certo.
            </p>
            @guest
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Criar conta grátis</a>
                    <a class="btn btn-ghost btn-lg" href="{{ route('login') }}">Entrar na conta</a>
                </div>
            @else
                <a class="btn btn-primary btn-lg" href="{{ $panelRoute }}">Ir para o meu painel</a>
            @endguest
        </div>
    </section>
</x-layouts.public>
