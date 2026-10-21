<x-layouts.public
    title="Arcana Tarot — Consultas de Tarô Online"
    description="Consultas de tarô online com atendentes especializados. Agende sua leitura com quem entende dos arcanos."
>

@php
    // Painel correto conforme o papel do usuário autenticado.
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('register');
@endphp

    {{-- Hero --}}
    <section class="arcana-hero arcana-stars">
        <div class="container-wide">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="arcana-eyebrow mb-3">Leitura de cartas · Oráculo · Conselhos</p>
                    <h1 class="arcana-hero__title">
                        As respostas que você busca<br>
                        já esperam na mesa.
                    </h1>
                    <div class="arcana-hero__divider" aria-hidden="true"></div>
                    <p class="arcana-lead mb-4">
                        Converse com atendentes especializados em tarô, tarô cigano e cartas
                        ancestrais. Escolha quem combina com a sua pergunta, agende e receba
                        a leitura completa em um ambiente reservado.
                    </p>

                    <div class="d-flex flex-wrap gap-3">
                        @guest
                            <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Começar agora</a>
                            <a class="btn btn-ghost btn-lg" href="{{ route('login') }}">Entrar na conta</a>
                        @else
                            <a class="btn btn-primary btn-lg" href="{{ $panelRoute }}">Ir para o painel</a>
                        @endguest
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="arcana-feature text-center">
                        <span class="arcana-feature__icon mb-3" aria-hidden="true">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                        </span>
                        <h2 class="arcana-feature__title">Uma leitura para cada pergunta</h2>
                        <p class="arcana-feature__text">
                            Amor, carreira, decisões ou reencontro — cada atendente tem um
                            método e um foco próprios. Encontre o seu.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    {{-- Como funciona --}}
    <section class="arcana-section" id="como-funciona">
        <div class="container-wide">
            <div class="text-center mb-5">
                <p class="arcana-eyebrow mb-2">Como funciona</p>
                <h2 class="arcana-section__title">Três passos, uma leitura</h2>
                <p class="arcana-section__subtitle mx-auto">
                    Do agendamento à mensagem final, o caminho é simples e transparente.
                </p>
            </div>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="arcana-feature arcana-fade-up">
                        <span class="arcana-feature__icon" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                            </svg>
                        </span>
                        <h3 class="arcana-feature__title">1. Escolha o atendente</h3>
                        <p class="arcana-feature__text">
                            Compare especialidades, métodos e disponibilidade para encontrar
                            quem entende a sua pergunta.
                        </p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="arcana-feature arcana-fade-up arcana-fade-up-delay-1">
                        <span class="arcana-feature__icon" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>
                            </svg>
                        </span>
                        <h3 class="arcana-feature__title">2. Agende a consulta</h3>
                        <p class="arcana-feature__text">
                            Defina o tema, o formato (chat ou vídeo) e o melhor horário
                            para o seu momento.
                        </p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="arcana-feature arcana-fade-up arcana-fade-up-delay-2">
                        <span class="arcana-feature__icon" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M4 4h16v12H5.2L4 17.2V4z"/><path d="M8 9h8M8 12h5"/>
                            </svg>
                        </span>
                        <h3 class="arcana-feature__title">3. Receba a leitura</h3>
                        <p class="arcana-feature__text">
                            Acompanhe a consulta em tempo real e consulte o histórico
                            completo quando quiser.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    {{-- Atendentes --}}
    <section class="arcana-section arcana-section--alt" id="atendentes">
        <div class="container-wide">
            <div class="text-center mb-5">
                <p class="arcana-eyebrow mb-2">Atendentes</p>
                <h2 class="arcana-section__title">Especialistas por área</h2>
                <p class="arcana-section__subtitle mx-auto">
                    Cada consulta é conduzida por quem se dedica àquele tema há anos.
                </p>
            </div>

            <div class="row g-4">
                @foreach ([
                    ['Amor e relacionamentos', 'Leituras de pares, reencontros e caminhos do coração.'],
                    ['Carreira e dinheiro', 'Propósito profissional, decisões e prosperidade.'],
                    ['Desenvolvimento pessoal', 'Ciclos, bloqueios internos e novos começos.'],
                    ['Tarô cigano', 'Leituras amplas para perguntas complexas e temas externos.'],
                ] as $i => [$titulo, $texto])
                    <div class="col-sm-6 col-lg-3">
                        <div class="arcana-feature h-100 arcana-fade-up arcana-fade-up-delay-{{ $i % 4 }}">
                            <h3 class="arcana-feature__title fs-6">{{ $titulo }}</h3>
                            <p class="arcana-feature__text">{{ $texto }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>


    {{-- Dúvidas --}}
    <section class="arcana-section" id="faq">
        <div class="container-wide">
            <div class="row g-5">
                <div class="col-lg-5">
                    <p class="arcana-eyebrow mb-2">Dúvidas</p>
                    <h2 class="arcana-section__title">Antes de perguntar</h2>
                    <p class="arcana-section__subtitle">
                        As perguntas mais comuns de quem está chegando. Se a sua não estiver
                        aqui, a equipe de atendimento ajuda.
                    </p>
                    <a class="btn btn-ghost mt-3" href="{{ route('login') }}">Falar com a equipe</a>
                </div>

                <div class="col-lg-7">
                    <div class="accordion" id="faq-acordeao">
                        @foreach ([
                            ['Preciso saber de tarô?', 'Não. O método é com o atendente: alguns preferem conduzir a leitura, outros pedem apenas o tema e deixam a interpretação livre.'],
                            ['Como funciona a cobrança?', 'A consulta é contratada no ato do agendamento e o valor aparece claro antes de confirmar.'],
                            ['Posso cancelar a consulta?', 'Sim. Se a consulta ainda não começou, o cancelamento é liberado integralmente pela central de atendimento.'],
                            ['Minhas mensagens ficam privadas?', 'Sim. Todo o histórico da leitura fica visível apenas para você e para o atendente que a conduz.'],
                        ] as $i => [$pergunta, $resposta])
                            <div class="accordion-item bg-transparent border-bottom" style="border-color: rgba(201,168,76,.16) !important;">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed bg-transparent text-ivory shadow-none"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#faq-{{ $i }}"
                                            aria-expanded="false"
                                            aria-controls="faq-{{ $i }}">
                                        {{ $pergunta }}
                                    </button>
                                </h3>
                                <div id="faq-{{ $i }}" class="accordion-collapse collapse"
                                     data-bs-parent="#faq-acordeao">
                                    <div class="accordion-body text-ivory-muted">
                                        {{ $resposta }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Chamada final --}}
    <section class="arcana-section arcana-section--alt">
        <div class="container-wide text-center">
            <h2 class="arcana-section__title">Sua próxima resposta começa aqui</h2>
            <p class="arcana-section__subtitle mx-auto mb-4">
                Crie sua conta gratuita e converse com o primeiro atendente em poucos minutos.
            </p>
            @guest
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Criar conta grátis</a>
                    <a class="btn btn-ghost btn-lg" href="{{ route('login') }}">Já tenho conta</a>
                </div>
            @else
                <a class="btn btn-primary btn-lg" href="{{ $panelRoute }}">Ir para o painel</a>
            @endguest
        </div>
    </section>
</x-layouts.public>

