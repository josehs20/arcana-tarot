{{--
    "Quem está do outro lado" na tela dedicada de atendimento.

    O cartão principal é sempre o da outra parte (o cliente vê o atendente e
    vice-versa); o próprio cartão aparece resumido logo abaixo, para conferir o
    que a outra pessoa está vendo sem sair do atendimento.
--}}
@php
    $counterparty = $isClient ? $parties['attendant'] : $parties['client'];
    $own = $isClient ? $parties['client'] : $parties['attendant'];
@endphp

<x-ui.card class="mb-4" data-consultation-parties>
    <x-ui.card-body>
        <p class="stat-card__label mb-3">
            {{ $isClient ? 'Seu atendente' : 'Cliente em atendimento' }}
        </p>

        @include('consultations.partials.party-card', [
            'party' => $counterparty,
            'heading' => 'Do outro lado',
        ])

        <details class="party-card__own mt-3">
            <summary class="party-card__own-summary">
                Seus dados neste atendimento
            </summary>

            <p class="party-card__own-name mt-2 mb-1">
                {{ $own['name'] }}
                <span class="badge bg-secondary ms-1" data-presence-role="{{ $own['role'] }}" role="status">—</span>
            </p>
            <p class="form-text mt-0 mb-0">
                É isso que {{ $isClient ? 'o atendente' : 'o cliente' }} vê no cartão de identificação.
                Seus dados de contato (e-mail e telefone) não são exibidos no chat.
            </p>
        </details>
    </x-ui.card-body>
</x-ui.card>
