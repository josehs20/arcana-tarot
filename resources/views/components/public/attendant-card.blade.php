{{--
    Cartão público do atendente.

    Exibe apenas dados aprovados para divulgação: foto opcional, nome
    profissional, apresentação curta, especialidades, modalidades com preço
    por minuto (calculado no servidor) e status atual. Nunca recebe e-mail,
    comissão ou qualquer dado financeiro.

    Uso:
        <x-public.attendant-card
            :attendant="$attendant"
            :modalities="$options"
        />
--}}
@props([
    'attendant',
    'requestable' => false,
    'modalities' => [],
])

@php
    $status = $attendant->publicStatus();
    $photoUrl = $attendant->photoUrl();
    $specialties = array_slice($attendant->specialties ?? [], 0, 3);
@endphp

<article {{ $attributes->merge(['class' => 'attendant-card h-100']) }}>
    <div class="attendant-card__head">
        <div class="attendant-avatar">
            @if ($photoUrl)
                <img src="{{ $photoUrl }}" alt="" width="72" height="72" loading="lazy">
            @else
                <span class="attendant-avatar__initials" aria-hidden="true">{{ $attendant->initials() }}</span>
            @endif
        </div>

        <div class="min-w-0">
            <h3 class="attendant-card__name">
                <a href="{{ route('attendants.show', $attendant) }}"
                   aria-label="Ver perfil de {{ $attendant->display_name }}">{{ $attendant->display_name }}</a>
            </h3>
            <x-ui.status-badge :status="$status" />
        </div>
    </div>

    @if ($attendant->bio)
        <p class="attendant-card__bio">{{ Str::limit($attendant->bio, 130) }}</p>
    @endif

    @if ($specialties !== [])
        <ul class="attendant-card__specialties list-unstyled mb-0" aria-label="Especialidades">
            @foreach ($specialties as $specialty)
                <li><x-ui.badge variant="gold">{{ $specialty }}</x-ui.badge></li>
            @endforeach
        </ul>
    @endif

    @if ($modalities !== [])
        <ul class="attendant-card__modalities list-unstyled mb-0" aria-label="Modalidades e preços">
            @foreach ($modalities as $modality)
                <li>
                    <x-ui.icon :name="$modality['icon']" width="14" height="14" />
                    <span>{{ $modality['label'] }}</span>
                    <strong>{{ $modality['price_label'] ?? 'Sob consulta' }}<small>/min</small></strong>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="attendant-card__footer">
        <p class="attendant-card__price mb-0">
            @if ($modalities !== [])
                Preço confirmado pelo servidor
            @else
                Preço não configurado
            @endif
        </p>
        @if ($requestable)
            <div class="d-flex align-items-center gap-2">
                <a class="attendant-card__link" href="{{ route('attendants.show', $attendant) }}">Ver perfil</a>
                <form method="POST" action="{{ route('client.consultations.store') }}">
                    @csrf
                    <input type="hidden" name="attendant_id" value="{{ $attendant->user_id }}">
                    <x-ui.button type="submit" variant="primary" size="sm">Solicitar</x-ui.button>
                </form>
            </div>
        @else
            <span class="attendant-card__link" aria-hidden="true">Ver perfil <span aria-hidden="true">&rarr;</span></span>
        @endif
    </div>
</article>
