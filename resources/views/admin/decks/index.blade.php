<x-layouts.panel variant="admin" title="Baralhos e cartas | Arcana Tarot">
    <x-layouts.page-header
        title="Baralhos e cartas"
        subtitle="Configure o catálogo usado nas consultas. Apenas baralhos e cartas ativos aparecem no seletor."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.decks.create') }}">Novo baralho</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.decks.index') }}" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome ou identificador"
                        maxlength="120"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">Estado</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Ativos</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inativos</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($decks->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhum baralho encontrado"
                    text="Crie o primeiro baralho para começar a montar o catálogo de cartas."
                >
                    <a class="btn btn-primary btn-sm mt-2" href="{{ route('admin.decks.create') }}">Criar baralho</a>
                </x-ui.empty-state>
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Baralhos cadastrados</caption>
                    <thead>
                        <tr>
                            <th scope="col">Baralho</th>
                            <th scope="col">Cartas</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Atualizado em</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($decks as $deck)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="decimal-mark" aria-hidden="true">✦</span>
                                        <div>
                                            <a class="fw-medium" href="{{ route('admin.decks.cards.index', $deck) }}">
                                                {{ $deck->name }}
                                            </a>
                                            <div class="form-text">
                                                <code>{{ $deck->slug }}</code>
                                                @if ($deck->description)
                                                    · {{ \Illuminate\Support\Str::limit($deck->description, 80) }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    {{ $deck->cards_count }} total
                                    <div class="form-text">{{ $deck->active_cards_count }} ativas</div>
                                </td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$deck->is_active ? 'online' : 'inactive'"
                                        :label="$deck->is_active ? 'Ativo' : 'Inativo'"
                                    />
                                </td>
                                <td class="text-nowrap">{{ $deck->updated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.cards.index', $deck) }}">
                                            Cartas
                                        </a>
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.edit', $deck) }}">
                                            Editar
                                        </a>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.decks.status', $deck) }}"
                                            data-confirm="{{ $deck->is_active
                                                ? 'Inativar este baralho? As cartas e imagens permanecem preservadas, mas deixam de aparecer no seletor.'
                                                : 'Ativar este baralho? As cartas ativas voltam a ficar disponíveis no seletor.' }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $deck->is_active ? 0 : 1 }}">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :class="$deck->is_active ? 'text-danger' : ''"
                                            >
                                                {{ $deck->is_active ? 'Inativar' : 'Ativar' }}
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>{{ $decks->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

