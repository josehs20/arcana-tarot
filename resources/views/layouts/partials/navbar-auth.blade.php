@php
    $panelRoute = auth()->user()->isAttendant()
        ? route('attendant.dashboard')
        : route('client.dashboard');
@endphp

<x-layouts.panel-navbar
    sidebar-id="sidebarClient"
    :panel-route="$panelRoute"
    :area="auth()->user()->isAttendant() ? 'Área do atendente' : 'Área do cliente'"
/>
