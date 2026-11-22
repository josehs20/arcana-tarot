{{--
    Faixa de destaque do bônus de cadastro, exibida logo abaixo do topo da home.

    O bônus é configurado em /admin/financeiro/bonus e chega aqui pelo
    `FirstAccessBonus::signupOffer()`, que devolve null apenas quando não há
    oferta a anunciar (bônus desligado ou sem créditos configurados). O valor
    em reais é opcional: sem a tarifa do crédito a faixa mostra só a quantidade
    de créditos. Os textos, a imagem e o botão são editáveis no CMS.

    A faixa aparece para todos os visitantes — inclusive para quem já tem conta
    — para que o administrador consiga conferir o conteúdo publicado direto na
    home, sem depender de uma sessão anônima.
--}}
@php
    $bonus = $registrationBonus ?? null;
    $ctaLabel = $section['primary_cta_label'] ?? 'Criar conta e receber';
@endphp

@if ($bonus !== null)
    <section class="arcana-section arcana-section--alt" id="bonus-cadastro" aria-labelledby="bonus-cadastro-title">
        <div class="container-wide">
            <div class="arcana-bonus__panel">
                <div class="arcana-bonus__text">
                    @if ($section['eyebrow'])
                        <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                    @endif

                    <h2 class="arcana-section__title" id="bonus-cadastro-title">{{ $section['title'] }}</h2>

                    @if ($section['subtitle'])
                        <p class="arcana-section__subtitle">{{ $section['subtitle'] }}</p>
                    @endif

                    @include('public.partials.sections.section-body')
                </div>

                <div class="arcana-bonus__offer">
                    <p class="arcana-bonus__amount">
                        +{{ $bonus['credits'] }}
                        <span class="arcana-bonus__amount-label">
                            {{ $bonus['credits'] === 1 ? 'crédito de presente' : 'créditos de presente' }}
                        </span>
                    </p>

                    @if ($bonus['amount_cents'] !== null)
                        <p class="arcana-bonus__value">
                            {{ \App\Support\Money::format($bonus['amount_cents']) }} em créditos na sua carteira
                        </p>
                    @endif

                    <div class="arcana-bonus__actions">
                        @if ($section['primary_cta_url'])
                            <a class="btn btn-primary btn-lg" href="{{ $section['primary_cta_url'] }}">{{ $ctaLabel }}</a>
                        @endif
                        @if ($section['secondary_cta_url'])
                            <a class="btn btn-ghost" href="{{ $section['secondary_cta_url'] }}">
                                {{ $section['secondary_cta_label'] ?? 'Já tenho conta' }}
                            </a>
                        @endif
                    </div>

                    <p class="arcana-bonus__note">
                        Bônus único por cliente, liberado após a confirmação do primeiro acesso.
                    </p>
                </div>
            </div>

            @include('public.partials.sections.section-media', ['class' => 'mt-4'])
        </div>
    </section>
@endif
