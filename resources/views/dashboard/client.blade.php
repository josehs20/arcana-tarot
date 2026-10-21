<x-layouts.panel variant="panel" title="Painel do Cliente | Arcana Tarot">
    <x-layouts.page-header
        title="Painel do Cliente"
        :subtitle="'Olá, ' . auth()->user()->name . '. Aqui você acompanha suas consultas.'"
    />

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Consultas</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Nenhuma consulta ainda</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Saldo</p>
                <p class="stat-card__value">R$ 0</p>
                <p class="stat-card__hint">Disponível para consultas</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Mensagens</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Conversas em andamento</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Status</p>
                <p class="stat-card__value">
                    <x-ui.status-badge status="online" label="Online" />
                </p>
                <p class="stat-card__hint">Você está conectado</p>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Minhas consultas</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <x-ui.empty-state
                        icon="✦"
                        title="Nenhuma consulta por aqui"
                        text="Quando você agendar uma leitura com um atendente, ela aparecerá nesta lista com todo o histórico de mensagens."
                    >
                        <a class="btn btn-primary mt-2" href="{{ route('home') }}#atendentes">Conhecer atendentes</a>
                    </x-ui.empty-state>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Atalhos</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <ul class="list-unstyled mb-0 d-grid gap-2">
                        <li><a class="btn btn-ghost w-100" href="{{ route('home') }}#como-funciona">Como funciona</a></li>
                        <li><a class="btn btn-ghost w-100" href="{{ route('home') }}#faq">Dúvidas frequentes</a></li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
