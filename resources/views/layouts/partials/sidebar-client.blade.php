@php
    // O mesmo layout atende clientes e atendentes; o painel muda conforme o papel.
    $user = auth()->user();
    $isAttendant = $user->isAttendant();
    $panelRoute = $isAttendant ? 'attendant.dashboard' : 'client.dashboard';
    $historyRoute = $isAttendant ? 'attendant.history' : 'client.history';

    $groups = [
        [
            'label' => 'Meu espaço',
            'links' => [
                ['route' => $panelRoute, 'pattern' => ['client.dashboard', 'attendant.dashboard'], 'label' => 'Painel', 'icon' => 'dashboard'],
                ['route' => $historyRoute, 'pattern' => ['client.history', 'attendant.history'], 'label' => 'Histórico', 'icon' => 'clock'],
            ],
        ],
    ];

    if ($user->isClient()) {
        $groups[] = [
            'label' => 'Consultas',
            'links' => [
                ['route' => 'client.credits.index', 'pattern' => 'client.credits.*', 'label' => 'Carteira e créditos', 'icon' => 'wallet'],
                ['route' => 'attendants.index', 'pattern' => 'attendants.*', 'label' => 'Atendentes', 'icon' => 'users'],
            ],
        ];
    }
@endphp

<x-layouts.panel-sidebar id="sidebarClient" :groups="$groups" label="Menu lateral da sua conta" />


