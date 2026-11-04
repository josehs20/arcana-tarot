@php($standalone = $standalone ?? false)

<section class="arcana-section" id="faq">
    <div class="container-wide">
        @if ($standalone)
            <div class="row justify-content-center">
                <div class="col-lg-9">
                    @include('public.partials.faq-accordion', ['faqs' => $faqs])

                    @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
                        <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
                            @if ($section['primary_cta_url'])
                                <a class="btn btn-ghost" href="{{ $section['primary_cta_url'] }}">
                                    {{ $section['primary_cta_label'] ?? 'Falar com a equipe' }}
                                </a>
                            @endif
                            @if ($section['secondary_cta_url'])
                                <a class="btn btn-ghost" href="{{ $section['secondary_cta_url'] }}">
                                    {{ $section['secondary_cta_label'] ?? 'Saiba mais' }}
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="row g-5">
                <div class="col-lg-5">
                    @if ($section['eyebrow'])
                        <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                    @endif
                    <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
                    @if ($section['subtitle'])
                        <p class="arcana-section__subtitle">{{ $section['subtitle'] }}</p>
                    @endif
                    @include('public.partials.sections.section-body')

                    @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @if ($section['primary_cta_url'])
                                <a class="btn btn-ghost" href="{{ $section['primary_cta_url'] }}">
                                    {{ $section['primary_cta_label'] ?? 'Falar com a equipe' }}
                                </a>
                            @endif
                            @if ($section['secondary_cta_url'])
                                <a class="btn btn-ghost" href="{{ $section['secondary_cta_url'] }}">
                                    {{ $section['secondary_cta_label'] ?? 'Saiba mais' }}
                                </a>
                            @endif
                        </div>
                    @endif

                    @include('public.partials.sections.section-media', ['class' => 'mt-4'])
                </div>

                <div class="col-lg-7">
                    @include('public.partials.faq-accordion', ['faqs' => $faqs])
                </div>
            </div>
        @endif
    </div>
</section>
