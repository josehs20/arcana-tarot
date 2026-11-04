@php
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('register');
@endphp

<section class="arcana-hero arcana-stars" id="inicio">
    <div class="container-wide">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                @if ($section['eyebrow'])
                    <p class="arcana-eyebrow mb-3">{{ $section['eyebrow'] }}</p>
                @endif
                <h1 class="arcana-hero__title">{{ $section['title'] }}</h1>
                <div class="arcana-hero__divider" aria-hidden="true"></div>
                <p class="arcana-lead mb-4">{{ $section['subtitle'] ?: $section['body'] }}</p>

                <div class="d-flex flex-wrap gap-3">
                    @guest
                        @if ($section['primary_cta_url'])
                            <a class="btn btn-primary btn-lg" href="{{ $section['primary_cta_url'] }}">{{ $section['primary_cta_label'] ?? 'Começar agora' }}</a>
                        @endif
                        @if ($section['secondary_cta_url'])
                            <a class="btn btn-ghost btn-lg" href="{{ $section['secondary_cta_url'] }}">{{ $section['secondary_cta_label'] ?? 'Entrar na conta' }}</a>
                        @endif
                    @else
                        <a class="btn btn-primary btn-lg" href="{{ $panelRoute }}">Ir para o painel</a>
                    @endguest
                </div>

                <p class="small text-ivory-dim mt-3 mb-0">
                    É tarólogo?
                    <a class="text-gold" href="{{ route('work-with-us.index') }}">Trabalhe conosco <span aria-hidden="true">&rarr;</span></a>
                </p>
            </div>

            <div class="col-lg-5">
                @if ($section['image_url'])
                    <figure class="arcana-hero__figure">
                        <img class="arcana-hero__image"
                             src="{{ $section['image_url'] }}"
                             alt="{{ $section['image_alt'] ?? '' }}"
                             fetchpriority="high">
                    </figure>
                @else
                    <div class="arcana-hero__visual" aria-hidden="true">
                        <span class="arcana-hero__orbit"></span>
                        <div class="arcana-tarot-card arcana-tarot-card--back">
                            <span>✦</span>
                        </div>
                        <div class="arcana-tarot-card arcana-tarot-card--front">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.1">
                                <circle cx="12" cy="12" r="4.5"/>
                                <path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/>
                            </svg>
                            <span class="arcana-tarot-card__name">Arcana</span>
                            <span class="arcana-tarot-card__mark">XVII</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
