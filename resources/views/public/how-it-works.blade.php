@php
    $site ??= app(\App\Support\SiteContent::class);
    $faqSection = $site->section('faq');
@endphp

<x-layouts.public
    :title="'Como funciona — '.$site->name()"
    :description="$section['subtitle'] ?: 'Entenda o passo a passo para escolher um atendente, agendar sua consulta e receber a leitura no '.$site->name().'.'"
>
    <x-public.page-hero
        :eyebrow="$section['eyebrow'] ?: 'Como funciona'"
        :title="$section['title'] ?: 'Como funciona'"
        :description="$section['subtitle']"
        current="Como funciona"
    >
        <x-slot:actions>
            <a class="btn btn-primary btn-lg" href="{{ route('attendants.index') }}">Escolher atendente</a>
            <a class="btn btn-ghost btn-lg" href="{{ route('pages.faq') }}">Ver dúvidas frequentes</a>
        </x-slot:actions>
    </x-public.page-hero>

    @include('public.partials.sections.how-it-works', [
        'section' => $section,
        'standalone' => true,
    ])

    @include('public.partials.sections.faq', [
        'section' => $faqSection,
        'faqs' => $faqs,
    ])

    @include('public.partials.sections.final-cta', [
        'section' => $finalCta,
    ])
</x-layouts.public>
