@php
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('register');
@endphp

<section class="arcana-section arcana-section--alt" id="{{ $anchor ?? 'comecar' }}">
    <div class="container-wide text-center">
        @if ($section['eyebrow'])
            <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
        @endif
        <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
        @if ($section['subtitle'])
            <p class="arcana-section__subtitle mx-auto mb-4">{{ $section['subtitle'] }}</p>
        @endif
        @include('public.partials.sections.section-body', ['class' => 'mx-auto mb-4'])

        @include('public.partials.sections.section-media', ['class' => 'mx-auto mb-4'])
        @guest
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                @if ($section['primary_cta_url'])
                    <a class="btn btn-primary btn-lg" href="{{ $section['primary_cta_url'] }}">
                        {{ $section['primary_cta_label'] ?? 'Criar conta grátis' }}
                    </a>
                @endif
                @if ($section['secondary_cta_url'])
                    <a class="btn btn-ghost btn-lg" href="{{ $section['secondary_cta_url'] }}">
                        {{ $section['secondary_cta_label'] ?? 'Já tenho conta' }}
                    </a>
                @endif
            </div>
        @else
            <a class="btn btn-primary btn-lg" href="{{ $panelRoute }}">Ir para o painel</a>
        @endguest
    </div>
</section>
