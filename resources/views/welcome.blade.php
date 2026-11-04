@php
    $site ??= app(\App\Support\SiteContent::class);
    $sections ??= $site->sections();
    $faqs ??= $site->faqs();

    // A ordem de exibição é definida pelo CMS (sort_order) e pode ser
    // alterada pelo administrador sem editar o Blade.
    $orderedSections = collect($sections)->sortBy('sort_order')->all();

    // Cada chave do CMS aponta para uma partial reutilizada também nas
    // páginas dedicadas (como funciona, dúvidas e diretório).
    $sectionPartials = [
        'hero' => 'public.partials.sections.hero',
        'attendants' => 'public.partials.sections.attendants',
        'how_it_works' => 'public.partials.sections.how-it-works',
        'tarot_cards' => 'public.partials.sections.tarot-cards',
        'faq' => 'public.partials.sections.faq',
        'work_with_us' => 'public.partials.sections.work-with-us',
        'final_cta' => 'public.partials.sections.final-cta',
    ];
@endphp

<x-layouts.public
    :title="$site->name().' — Consultas de Tarô Online'"
    :description="$site->description()"
>
    @foreach ($orderedSections as $sectionKey => $section)
        @continue(! $section['is_published'])
        @continue(! isset($sectionPartials[$sectionKey]))

        @include($sectionPartials[$sectionKey], ['section' => $section])
    @endforeach
</x-layouts.public>
