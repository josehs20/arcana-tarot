@php($tarotDecks = $tarotDecks ?? collect())

<section class="arcana-section" id="cartas">
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

            @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        @if ($section['primary_cta_url'])
                            <a class="btn btn-ghost" href="{{ $section['primary_cta_url'] }}">
                                {{ $section['primary_cta_label'] ?? 'Encontrar um atendente' }}
                            </a>
                        @endif
                        @if ($section['secondary_cta_url'])
                            <a class="btn btn-ghost" href="{{ $section['secondary_cta_url'] }}">
                                {{ $section['secondary_cta_label'] ?? 'Saiba mais' }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        @include('public.partials.sections.section-body', ['class' => 'mb-4'])

        @include('public.partials.sections.section-media', ['class' => 'mb-4'])

        @if ($tarotDecks->isEmpty())
            <x-ui.empty-state
                icon="✦"
                title="Baralhos em preparação"
                text="O catálogo de baralhos está sendo atualizado. Enquanto isso, conheça os atendentes e as tradições com que cada um trabalha."
            >
                <a class="btn btn-ghost mt-2" href="{{ route('attendants.index') }}">Ver atendentes</a>
            </x-ui.empty-state>
        @else
            <div class="row g-4">
                @foreach ($tarotDecks as $deck)
                    <div class="col-md-6 col-xl-4">
                        <article class="deck-card">
                            <div class="deck-card__face" aria-hidden="true">
                                <span class="deck-card__mark">✦</span>
                                @if ($deck->cards_count > 0)
                                    <span class="deck-card__count">{{ $deck->cards_count }} cartas</span>
                                @endif
                            </div>

                            <div class="deck-card__body">
                                <h3 class="deck-card__name">{{ $deck->name }}</h3>
                                @if ($deck->description)
                                    <p class="deck-card__text">
                                        {{ \Illuminate\Support\Str::limit($deck->description, 140) }}
                                    </p>
                                @endif
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
