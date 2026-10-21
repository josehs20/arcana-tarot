<x-layouts.panel variant="panel" title="Painel do Atendente | Arcana Tarot">
    <x-layouts.page-header
        title="Painel do Atendente"
        :subtitle="'Olá, ' . auth()->user()->name . '. Sua agenda e seus atendimentos estão aqui.'"
    >
        <x-slot:actions>
            <x-ui.status-badge status="online" label="Online" />
        </x-slot:actions>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Atendimentos hoje</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Nenhum atendimento marcado</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Receita do mês</p>
                <p class="stat-card__value">R$ 0</p>
                <p class="stat-card__hint">Após comissões</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Avaliação</p>
                <p class="stat-card__value">—</p>
                <p class="stat-card__hint">Ainda sem avaliações</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Status</p>
                <p class="stat-card__value">
                    <x-ui.status-badge status="online" label="Online" />
                </p>
                <p class="stat-card__hint">Aceitando consultas</p>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Agenda</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <x-ui.empty-state
                        icon="☾"
                        title="Agenda vazia"
                        text="As consultas que você agendar aparecem aqui em ordem cronológica, com o status de cada atendimento."
                    />
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Meu perfil</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row mb-0 small">
                        <dt class="col-6 text-ivory-muted fw-normal">Nome</dt>
                        <dd class="col-6">{{ auth()->user()->name }}</dd>

                        <dt class="col-6 text-ivory-muted fw-normal">E-mail</dt>
                        <dd class="col-6">{{ auth()->user()->email }}</dd>

                        <dt class="col-6 text-ivory-muted fw-normal">Status</dt>
                        <dd class="col-6"><x-ui.status-badge status="online" /></dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
