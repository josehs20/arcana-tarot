@php
    /**
     * Vitrine de produtos ativos. Sem produto ativo a seção não é renderizada.
     *
     * O preço e os créditos exibidos vêm do modelo carregado pelo
     * `HomeController`; o formulário envia apenas o `operation_id` e o servidor
     * relê o preço antes de criar a cobrança.
     */
    $products = $products ?? collect();
    $productCheckoutEnabled = $productCheckoutEnabled ?? false;
    $isClient = auth()->check() && auth()->user()->isClient();
    $ctaUrl = $section['primary_cta_url'] ?? ($isClient ? route('client.purchases.index') : route('register'));
    $ctaLabel = $section['primary_cta_label'] ?? ($isClient ? 'Minhas compras' : 'Criar conta para comprar');
@endphp

@if ($products->isNotEmpty())
    <section class="arcana-section" id="produtos">
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
                    <a class="btn btn-ghost" href="{{ $ctaUrl }}">{{ $ctaLabel }}</a>
                </div>
            </div>

            @include('public.partials.sections.section-body', ['class' => 'mb-4'])

            @include('public.partials.sections.section-media', ['class' => 'mb-4'])

            <div class="row g-4">
                @foreach ($products as $product)
                    <div class="col-md-6 col-xl-4">
                        <article class="product-card">
                            @if ($product->imageUrl())
                                <img
                                    class="product-card__image"
                                    src="{{ $product->imageUrl() }}"
                                    alt=""
                                    loading="lazy"
                                >
                            @else
                                <div class="product-card__image product-card__image--empty" aria-hidden="true">✦</div>
                            @endif

                            <div class="product-card__body">
                                <p class="product-card__credits">{{ $product->creditsLabel() }}</p>
                                <h3 class="product-card__title">{{ $product->title }}</h3>

                                @if ($product->description)
                                    <p class="product-card__text">{{ $product->summary(180) }}</p>
                                @endif

                                <p class="product-card__price">{{ $product->priceLabel() }}</p>

                                @if (! auth()->check())
                                    <a class="btn btn-primary w-100" href="{{ route('register') }}">
                                        Criar conta e comprar
                                    </a>
                                @elseif (! $isClient)
                                    <p class="product-card__note mb-0">A loja é exclusiva para clientes.</p>
                                @elseif ($productCheckoutEnabled)
                                    <form method="POST" action="{{ route('client.products.checkout.store', $product) }}">
                                        @csrf
                                        <input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button class="btn btn-primary w-100" type="submit">
                                            Comprar por {{ $product->priceLabel() }}
                                        </button>
                                    </form>
                                @else
                                    <p class="product-card__note mb-2">
                                        O checkout está indisponível neste ambiente.
                                    </p>
                                    <a class="btn btn-ghost w-100" href="{{ route('client.credits.index') }}">
                                        Ver minha carteira
                                    </a>
                                @endif
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>

            <p class="form-text mt-4 mb-0">
                A compra só é confirmada depois da verificação server-side do pagamento. Estornos ficam registrados no histórico para análise.
            </p>
        </div>
    </section>
@endif
