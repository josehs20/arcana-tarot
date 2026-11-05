{{--
    Cartão de identificação de uma das partes do atendimento.

    Recebe `$party` (array de `App\Support\ConsultationPartyPresenter`) e
    `$heading` (título do bloco). O cartão é só identificação: nome, papel,
    presença, tempo de casa e histórico. Tempo e dinheiro ficam nos cartões de
    indicadores do topo da tela, para o mesmo número não aparecer duas vezes.
    E-mail e telefone nunca aparecem aqui: o chat bloqueia a troca de contatos e
    o cartão não é uma porta lateral.
--}}
@php
    $isAttendantCard = ($party['role'] ?? null) === 'attendant';
@endphp

<article class="party-card" data-party-role="{{ $party['role'] }}">
    <p class="party-card__heading">{{ $heading }}</p>

    <div class="party-card__identity">
        @if (! empty($party['photo_url']))
            <img
                class="party-card__avatar party-card__avatar--photo"
                src="{{ $party['photo_url'] }}"
                alt="Foto de {{ $party['name'] }}"
                loading="lazy"
            >
        @else
            <span class="party-card__avatar" aria-hidden="true">{{ $party['initials'] }}</span>
        @endif

        <div class="party-card__who">
            <p class="party-card__name">{{ $party['name'] }}</p>
            <p class="party-card__role">
                {{ $party['role_label'] }}
                {{-- Presença atualizada por `resources/js/consultation-presence.js`. --}}
                <span
                    class="badge bg-secondary ms-1"
                    data-presence-role="{{ $party['role'] }}"
                    role="status"
                >—</span>
            </p>
        </div>
    </div>

    <dl class="party-card__facts">
   
    @if(!($isAttendantCard ?? false))
       <div class="party-card__fact">
            <dt>{{  'Cliente desde' }}</dt>
            <dd>{{  $party['member_since_label'] }}</dd>
        </div>
             <div class="party-card__fact">
               <dt>{{  'Data de nascimento' }}</dt>
            <dd>{{  $party['birth_date_label'] }}</dd>
        </div>
        @endif
 

        <div class="party-card__fact">
            <dt>Histórico</dt>
            <dd>
                @if (! empty($party['consultations_label']))
                    {{ $party['consultations_label'] }}
                @else
                    {{ $party['consultations_count'] }}
                    {{ $party['consultations_count'] === 1 ? 'atendimento' : 'atendimentos' }}
                @endif
            </dd>
        </div>
    </dl>

    @if (! empty($party['specialties']))
        <p class="party-card__specialties">
            @foreach ($party['specialties'] as $specialty)
                <span class="badge bg-secondary">{{ $specialty }}</span>
            @endforeach
        </p>
    @endif

    @if (! empty($party['bio']))
        <p class="party-card__bio">{{ $party['bio'] }}</p>
    @endif
</article>
