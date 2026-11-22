@php
    /**
     * Vitrine das promoções de créditos ativas.
     *
     * As campanhas vêm do `PromotionManager` (mesmo critério usado para
     * conceder o bônus na confirmação do pagamento), então a home nunca
     * anuncia uma oferta que o servidor não honraria. Sem promoção ativa a
     * seção inteira não é renderizada.
     */
    $promotions = $promotions ?? collect();
    $ctaUrl = $section['primary_cta_url']
        ?? (auth()->check() ? route('client.credits.index') : route('register'));
    $ctaLabel = $section['primary_cta_label']
        ?? (auth()->check() ? 'Escolher um pacote' : 'Criar conta e aproveitar');
@endphp

@if ($promotions->isNotEmpty())
    <section class="arcana-section" id="promocoes">
        <div class="container-wide">
            <div class="row align-items-end g-4 mb-5">
                <div class="col-lg-8">
                    @if ($section['eyebrow'])
                        <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                    @endif
                    <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
                    @if ($section['subtitle'])
                        <p class="arcana-section__subtitle">{{ $section['subtitle'] }}</p>
                    @endif
                </div>

                <div class="col-lg-4 text-lg-end">
                    <a class="btn btn-primary" href="{{ $ctaUrl }}">{{ $ctaLabel }}</a>
                </div>
            </div>

            @include('public.partials.sections.section-body', ['class' => 'mb-4'])

            @include('public.partials.sections.section-media', ['class' => 'mb-4'])

            <div class="row g-4">
                @foreach ($promotions as $promotion)
                    <div class="col-md-6 col-xl-4">
                        <article class="promo-card">
                            <p class="promo-card__bonus">+{{ $promotion->bonus_credits }} créditos</p>
                            <p class="promo-card__rule">{{ $promotion->headline() }}</p>

                            @if ($promotion->title)
                                <h3 class="promo-card__title">{{ $promotion->title }}</h3>
                            @endif

                            @if ($promotion->description)
                                <p class="promo-card__text">{{ $promotion->description }}</p>
                            @endif

                            @if ($promotion->ends_at !== null)
                                <p class="promo-card__deadline">
                                    Válida até {{ $promotion->ends_at->format('d/m/Y') }}
                                </p>
                            @endif
                        </article>
                    </div>
                @endforeach
            </div>

            <p class="form-text mt-4 mb-0">
                O bônus é creditado junto com a recarga, somente depois que o provedor de pagamento confirma a compra no servidor.
            </p>
        </div>
    </section>
@endif
