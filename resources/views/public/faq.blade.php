@php
    $site ??= app(\App\Support\SiteContent::class);
@endphp

<x-layouts.public
    :title="'Dúvidas frequentes — '.$site->name()"
    :description="$section['subtitle'] ?: 'Respostas sobre consultas, créditos, privacidade e o funcionamento do Arcana Tarot.'"
>
    <x-public.page-hero
        :eyebrow="$section['eyebrow'] ?: 'Central de dúvidas'"
        :title="$section['title'] ?: 'Dúvidas frequentes'"
        :description="$section['subtitle']"
        current="Dúvidas"
    />

    @include('public.partials.sections.faq', [
        'section' => $section,
        'faqs' => $faqs,
        'standalone' => true,
    ])

    <section class="arcana-section arcana-section--alt">
        <div class="container-wide">
            <div class="work-cta">
                <div class="work-cta__text">
                    <p class="arcana-eyebrow mb-2">Atendimento humano</p>
                    <h2 class="arcana-section__title">Não encontrou a sua pergunta?</h2>
                    <p class="arcana-section__subtitle mb-0">
                        A equipe do Arcana Tarot responde sobre créditos, agendamentos,
                        privacidade e qualquer detalhe antes de você começar.
                    </p>
                </div>

                <div class="work-cta__actions">
                    <a class="btn btn-primary btn-lg" href="{{ route('contact') }}">Falar com a equipe</a>
                    <a class="btn btn-ghost btn-lg" href="{{ route('pages.how-it-works') }}">Como funciona</a>
                </div>
            </div>
        </div>
    </section>

    @include('public.partials.sections.final-cta', [
        'section' => $finalCta,
    ])
</x-layouts.public>
