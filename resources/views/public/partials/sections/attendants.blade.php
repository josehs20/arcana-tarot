@php
    $attendants = $attendants ?? collect();
@endphp

<section class="arcana-section arcana-section--alt" id="atendentes">
    <div class="container-wide">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-5">
            <div>
                @if ($section['eyebrow'])
                    <p class="arcana-eyebrow mb-2">{{ $section['eyebrow'] }}</p>
                @endif
                <h2 class="arcana-section__title">{{ $section['title'] }}</h2>
                @if ($section['subtitle'])
                    <p class="arcana-section__subtitle">{{ $section['subtitle'] }}</p>
                @endif
                @include('public.partials.sections.section-body')
            </div>

            @if ($section['primary_cta_url'] || $section['secondary_cta_url'])
                <div class="d-flex flex-wrap gap-2">
                    @if ($section['primary_cta_url'])
                        <a class="btn btn-ghost" href="{{ $section['primary_cta_url'] }}">
                            {{ $section['primary_cta_label'] ?? 'Ver diretório completo' }}
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

        @include('public.partials.sections.section-media', ['class' => 'mb-4'])

        {{-- Ver comentário em `public/attendants/index.blade.php`: o cartão
             envia a solicitação direto e o erro precisa aparecer aqui. --}}
        @if ($errors->any())
            @include('layouts.partials.flash-messages', ['container' => 'mb-4'])
        @endif

        @if ($attendants->isEmpty())
            <x-ui.empty-state
                icon="☾"
                title="Nenhum atendente online neste momento"
                text="Todos os atendentes estão offline agora. Você pode conhecer os perfis publicados e voltar quando alguém estiver disponível."
            >
                <a class="btn btn-ghost mt-2" href="{{ route('attendants.index') }}">Ver todos os atendentes</a>
            </x-ui.empty-state>
        @else
            <div class="row g-4">
                @foreach ($attendants as $attendant)
                    <div class="col-md-6 col-xl-4">
                        <x-public.attendant-card
                            :attendant="$attendant"
                            :modalities="$modalityOptions[$attendant->getKey()] ?? []"
                        />
                    </div>
                @endforeach
            </div>

            <div class="text-center mt-5">
                <a class="btn btn-ghost" href="{{ route('attendants.index') }}">
                    Ver todos os atendentes
                </a>
            </div>
        @endif
    </div>
</section>
