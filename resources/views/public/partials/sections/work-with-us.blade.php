<section class="arcana-section" id="trabalhe-conosco">
    <div class="container-wide">
        <div class="work-cta">
            <div class="work-cta__text">
                @if ($section['eyebrow'])
                    <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                @endif
                <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
                @if ($section['subtitle'])
                    <p class="arcana-section__subtitle mb-2">{{ $section['subtitle'] }}</p>
                @endif
                @include('public.partials.sections.section-body', ['class' => 'mb-0'])
            </div>

            @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
                <div class="work-cta__actions">
                    @if ($section['primary_cta_url'])
                        <a class="btn btn-primary btn-lg" href="{{ $section['primary_cta_url'] }}">
                            {{ $section['primary_cta_label'] ?? 'Trabalhe conosco' }}
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

        @include('public.partials.sections.section-media', ['class' => 'mt-4'])
    </div>
</section>
