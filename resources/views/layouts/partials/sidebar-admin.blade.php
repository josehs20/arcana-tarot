@php
    $groups = [
        [
            'label' => 'Visão geral',
            'links' => [
                ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                ['route' => 'admin.consultations.index', 'pattern' => 'admin.consultations.index', 'label' => 'Consultas', 'icon' => 'activity'],
                ['route' => 'admin.consultations.safety', 'pattern' => 'admin.consultations.safety*', 'label' => 'Segurança do chat', 'icon' => 'shield'],
                ['route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'label' => 'Usuários', 'icon' => 'users'],
            ],
        ],
        [
            'label' => 'Gestão',
            'links' => [
                ['route' => 'admin.applications.index', 'pattern' => 'admin.applications.*', 'label' => 'Candidaturas', 'icon' => 'inbox', 'badge' => $pendingApplicationsCount, 'badge_label' => $pendingApplicationsCount === 1 ? '1 candidatura pendente' : $pendingApplicationsCount.' candidaturas pendentes'],
                ['route' => 'admin.attendants.index', 'pattern' => 'admin.attendants.*', 'label' => 'Atendentes', 'icon' => 'users'],
                ['route' => 'admin.testimonials.index', 'pattern' => 'admin.testimonials.*', 'label' => 'Depoimentos', 'icon' => 'star'],
                ['route' => 'admin.modalities.index', 'pattern' => 'admin.modalities.*', 'label' => 'Modalidades', 'icon' => 'phone'],
                ['route' => 'admin.decks.index', 'pattern' => 'admin.decks.*', 'label' => 'Baralhos', 'icon' => 'layers'],
                ['route' => 'admin.site.index', 'pattern' => 'admin.site.*', 'label' => 'Conteúdo do site', 'icon' => 'globe'],
            ],
        ],
        [
            'label' => 'Financeiro',
            'links' => [
                ['route' => 'admin.finance.index', 'pattern' => 'admin.finance.index', 'label' => 'Financeiro', 'icon' => 'wallet'],
                ['route' => 'admin.finance.packages.index', 'pattern' => 'admin.finance.packages.*', 'label' => 'Pacotes e recarga', 'icon' => 'layers'],
                ['route' => 'admin.finance.bonus.edit', 'pattern' => 'admin.finance.bonus.*', 'label' => 'Bônus de cadastro', 'icon' => 'sparkles'],
                ['route' => 'admin.payments.index', 'pattern' => 'admin.payments.*', 'label' => 'Pagamentos', 'icon' => 'credit-card'],
                ['route' => 'admin.commissions.index', 'pattern' => 'admin.commissions.*', 'label' => 'Comissões', 'icon' => 'percent'],
            ],
        ],
        [
            'label' => 'Sistema',
            'links' => [
                ['route' => 'admin.audit.index', 'pattern' => 'admin.audit.*', 'label' => 'Auditoria', 'icon' => 'shield'],
            ],
        ],
    ];
@endphp

<x-layouts.panel-sidebar id="sidebarAdmin" :groups="$groups" label="Menu lateral da administração" />

