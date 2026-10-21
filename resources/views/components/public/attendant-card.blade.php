{{--
    Cartão público do atendente.

    Exibe apenas dados aprovados para divulgação: foto opcional, nome
    profissional, apresentação curta, especialidades, preço por minuto
    (calculado no servidor) e status atual. Nunca recebe e-mail, comissão
    ou qualquer dado financeiro.

    Uso:
        <x-public.attendant-card :attendant="$attendant" :price="$pricePerMinute" />
--}}
@props([
    'attendant',
    'price' => null,
])

@php
    $status = $attendant->publicStatus();
    $statusLabel = $status === 'busy' ? 'Em consulta' : null;
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
            <x-ui.status-badge :status="$status" :label="$statusLabel" />
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

    <div class="attendant-card__footer">
        <p class="attendant-card__price mb-0">
            @if ($price)
                {{ $price }} <small>/ minuto</small>
            @else
                Valor sob consulta
            @endif
        </p>
        <span class="attendant-card__link" aria-hidden="true">Ver perfil <span aria-hidden="true">&rarr;</span></span>
    </div>
</article>
