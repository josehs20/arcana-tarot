{{--
    Badge de status. Sempre exibe TEXTO visível — a cor é apenas reforço.

    Uso:
        <x-ui.status-badge status="online" />
        <x-ui.status-badge status="busy" label="Em consulta" />
        <x-ui.status-badge status="under_review" />
--}}
@props([
    'status' => 'offline',
    'label' => null,
    'dot' => true,
])

@php
    $map = [
        // Presença do atendente
        'online' => ['label' => 'Online', 'dot' => 'online', 'text' => 'status-text--online'],
        'offline' => ['label' => 'Offline', 'dot' => 'offline', 'text' => 'status-text--offline'],
        'busy' => ['label' => 'Ocupado', 'dot' => 'busy', 'text' => 'status-text--busy'],

        // Candidaturas de atendente
        'pending' => ['label' => 'Pendente', 'dot' => 'busy', 'text' => 'status-text--busy'],
        'under_review' => ['label' => 'Em análise', 'dot' => 'busy', 'text' => 'status-text--busy'],
        'approved' => ['label' => 'Aprovado', 'dot' => 'online', 'text' => 'status-text--online'],
        'rejected' => ['label' => 'Rejeitado', 'dot' => 'offline', 'text' => 'status-text--offline'],

        // Consultas
        'requested' => ['label' => 'Solicitada', 'dot' => 'busy', 'text' => 'status-text--busy'],
        'active' => ['label' => 'Em andamento', 'dot' => 'online', 'text' => 'status-text--online'],
        'ended' => ['label' => 'Encerrada', 'dot' => 'offline', 'text' => 'status-text--offline'],
        'cancelled' => ['label' => 'Cancelada', 'dot' => 'offline', 'text' => 'status-text--offline'],
        'expired' => ['label' => 'Expirada', 'dot' => 'offline', 'text' => 'status-text--offline'],
    ];

    $entry = $map[$status] ?? $map['offline'];
    $badgeLabel = $label ?? $entry['label'];
@endphp

<span {{ $attributes->merge(['class' => 'status-indicator']) }}>
    @if ($dot)
        <span class="status-dot status-dot--{{ $entry['dot'] }}" aria-hidden="true"></span>
    @endif
    <span class="{{ $entry['text'] }}">{{ $badgeLabel }}</span>
</span>

