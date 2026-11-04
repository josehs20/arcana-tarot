@props([
    'rows',
    'showCommission' => false,
    'revenueLabel' => 'Receita',
    'indexRoute' => null,
])

@php
    $totalConsultations = collect($rows)->sum('consultations');
@endphp

<x-ui.card class="h-100">
    <x-ui.card-header class="dashboard-card-head">
        <div>
            <h3 class="dashboard-card-head__title">Atendimentos por modalidade</h3>
            <p class="dashboard-card-head__meta">Dados reais do histórico financeiro</p>
        </div>
        <span class="dashboard-card-head__total">{{ number_format($totalConsultations, 0, ',', '.') }}</span>
    </x-ui.card-header>

    @if ($totalConsultations === 0)
        <x-ui.card-body>
            <x-ui.empty-state
                icon="☾"
                title="Nenhum atendimento por modalidade ainda"
                text="Os números aparecem depois da primeira consulta solicitada."
            />
        </x-ui.card-body>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">Atendimentos e valores por modalidade</caption>
                <thead>
                    <tr>
                        <th scope="col">Modalidade</th>
                        <th scope="col">Atendimentos</th>
                        <th scope="col">Concluídos</th>
                        <th scope="col">{{ $revenueLabel }}</th>
                        @if ($showCommission)
                            <th scope="col">Comissão líquida</th>
                        @endif
                        @if ($indexRoute)
                            <th scope="col" class="text-end">Detalhes</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="text-nowrap">
                                <span class="fw-medium">{{ $row['label'] }}</span>
                            </td>
                            <td>{{ $row['consultations'] }}</td>
                            <td>{{ $row['completed'] }}</td>
                            <td class="text-nowrap">{{ \App\Support\Money::format($row['revenue_cents']) }}</td>
                            @if ($showCommission)
                                <td class="text-nowrap">{{ \App\Support\Money::format($row['commission_cents']) }}</td>
                            @endif
                            @if ($indexRoute)
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route($indexRoute, ['modality' => $row['modality']]) }}">Ver</a>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
