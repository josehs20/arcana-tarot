<x-layouts.panel variant="admin" :title="'Cartas — ' . $deck->name . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Cartas de ' . $deck->name"
        :subtitle="$deck->is_active
            ? $activeCards . ' de ' . $totalCards . ' cartas ativas. Apenas cartas ativas aparecem no seletor.'
            : 'Baralho inativo: as cartas continuam preservadas, mas não podem ser escolhidas em novos envios.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.index') }}">Voltar aos baralhos</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.edit', $deck) }}">Editar baralho</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.decks.cards.create', $deck) }}">Nova carta</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.decks.cards.index', $deck) }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="status">Estado das cartas</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todas</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Ativas</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inativas</option>
                    </select>
                </div>

                <div class="col-md-4 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.cards.index', $deck) }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    @unless ($deck->is_active)
        <x-ui.alert type="warning" :dismissible="false" class="mb-4">
            Este baralho está inativo. Nenhuma carta dele será exibida no seletor, mesmo que a carta esteja ativa.
        </x-ui.alert>
    @endunless

    <x-ui.card>
        @if ($cards->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhuma carta cadastrada"
                    text="Cadastre a primeira carta com imagem, ordem e significados para montar este baralho."
                >
                    <a class="btn btn-primary btn-sm mt-2" href="{{ route('admin.decks.cards.create', $deck) }}">
                        Cadastrar carta
                    </a>
                </x-ui.empty-state>
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Cartas do baralho {{ $deck->name }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Carta</th>
                            <th scope="col">Ordem</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Significados</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cards as $card)
                            @php
                                $isFirstCard = $loop->first;
                                $isLastCard = $loop->last;
                            @endphp
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        @if ($card->hasStoredImage())
                                            <a href="{{ $card->imageUrl() }}" target="_blank" rel="noopener">
                                                <img
                                                    class="tarot-thumb"
                                                    src="{{ $card->thumbnailUrl() }}"
                                                    alt="Imagem frontal de {{ $card->name }}"
                                                    width="56"
                                                    height="84"
                                                    loading="lazy"
                                                    decoding="async"
                                                >
                                            </a>
                                        @else
                                            <span class="tarot-thumb tarot-thumb--empty" aria-hidden="true">✦</span>
                                        @endif

                                        <div>
                                            <a class="fw-medium" href="{{ route('admin.decks.cards.edit', [$deck, $card]) }}">
                                                {{ $card->name }}
                                            </a>
                                            <div class="form-text"><code>{{ $card->slug }}</code></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <div class="d-inline-flex gap-1" aria-label="Reordenar {{ $card->name }}">
                                        <form method="POST" action="{{ route('admin.decks.cards.move', [$deck, $card]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :disabled="$isFirstCard"
                                                :aria-label="'Mover para cima: ' . $card->name"
                                            >↑</x-ui.button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.decks.cards.move', [$deck, $card]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :disabled="$isLastCard"
                                                :aria-label="'Mover para baixo: ' . $card->name"
                                            >↓</x-ui.button>
                                        </form>
                                    </div>
                                    <span class="ms-2">{{ $card->sort_order }}</span>
                                </td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$card->is_active ? 'online' : 'inactive'"
                                        :label="$card->is_active ? 'Ativa' : 'Inativa'"
                                    />
                                </td>
                                <td>
                                    <span class="tarot-meaning">
                                        {{ \Illuminate\Support\Str::limit($card->meaning_upright ?: 'Normal não informado', 70) }}
                                    </span>
                                    <div class="form-text">
                                        Invertido: {{ \Illuminate\Support\Str::limit($card->meaning_reversed ?: 'não informado', 55) }}
                                    </div>
                                </td>

                                <td>
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.cards.edit', [$deck, $card]) }}">
                                            Editar
                                        </a>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.decks.cards.status', [$deck, $card]) }}"
                                            data-confirm="{{ $card->is_active
                                                ? 'Inativar esta carta? A imagem e o histórico de mensagens serão preservados.'
                                                : 'Ativar esta carta? Ela só aparecerá no seletor se o baralho também estiver ativo.' }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $card->is_active ? 0 : 1 }}">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :class="$card->is_active ? 'text-danger' : ''"
                                            >
                                                {{ $card->is_active ? 'Inativar' : 'Ativar' }}
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>{{ $cards->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

