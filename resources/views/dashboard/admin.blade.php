<x-layouts.panel variant="admin" title="Painel do Administrador | Arcana Tarot">
    <x-layouts.page-header
        title="Painel do Administrador"
        :subtitle="'Olá, ' . auth()->user()->name . '. Visão geral da plataforma.'"
    />

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Usuários</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Clientes e atendentes</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Atendentes</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Cadastrados na plataforma</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Consultas</p>
                <p class="stat-card__value">0</p>
                <p class="stat-card__hint">Concluídas no total</p>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <p class="stat-card__label">Receita</p>
                <p class="stat-card__value">R$ 0</p>
                <p class="stat-card__hint">Volume transacionado</p>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Atendimentos recentes</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <x-ui.empty-state
                        icon="✧"
                        title="Nenhum registro"
                        text="As consultas realizadas na plataforma vão aparecer aqui, com o status de cada atendimento."
                    />
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card>
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Aprovações</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <x-ui.empty-state
                        icon="◈"
                        title="Nada pendente"
                        text="Novos pedidos de atendimento aguardando análise aparecem nesta seção."
                    />
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
