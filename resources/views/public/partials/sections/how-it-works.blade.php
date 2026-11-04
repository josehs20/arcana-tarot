@php($standalone = $standalone ?? false)

<section class="arcana-section" id="como-funciona">
    <div class="container-wide">
        @unless ($standalone)
            <div class="text-center mb-5">
                @if ($section['eyebrow'])
                    <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                @endif
                <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
                @if ($section['subtitle'])
                    <p class="arcana-section__subtitle mx-auto">{{ $section['subtitle'] }}</p>
                @endif
                @include('public.partials.sections.section-body', ['class' => 'mx-auto'])
            </div>
        @endunless

        @include('public.partials.sections.section-media', ['class' => 'mb-5'])

        <div class="row g-4">
            <div class="col-md-4">
                <div class="arcana-feature arcana-fade-up">
                    <span class="arcana-feature__icon" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                        </svg>
                    </span>
                    <span class="arcana-feature__step">Passo 01</span>
                    <h3 class="arcana-feature__title">Escolha o atendente</h3>
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
                    <span class="arcana-feature__step">Passo 02</span>
                    <h3 class="arcana-feature__title">Agende a consulta</h3>
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
                    <span class="arcana-feature__step">Passo 03</span>
                    <h3 class="arcana-feature__title">Receba a leitura</h3>
                    <p class="arcana-feature__text">
                        Acompanhe a consulta em tempo real e consulte o histórico
                        completo quando quiser.
                    </p>
                </div>
            </div>
        </div>

        @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
            <div class="d-flex flex-wrap gap-3 justify-content-center mt-5">
                @if ($section['primary_cta_url'])
                    <a class="btn btn-primary btn-lg" href="{{ $section['primary_cta_url'] }}">
                        {{ $section['primary_cta_label'] ?? 'Começar agora' }}
                    </a>
                @endif
                @if ($section['secondary_cta_url'])
                    <a class="btn btn-ghost btn-lg" href="{{ $section['secondary_cta_url'] }}">
                        {{ $section['secondary_cta_label'] ?? 'Saiba mais' }}
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>
