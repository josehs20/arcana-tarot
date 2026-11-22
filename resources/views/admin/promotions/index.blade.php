<x-layouts.panel variant="admin" title="Promoções">
    <x-layouts.page-header
        title="Promoções"
        subtitle="Campanhas &quot;Compre X créditos e ganhe Y&quot;. As ativas aparecem na home e na carteira, e o bônus é creditado quando o pagamento é confirmado pelo provedor."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.promotions.create') }}">Nova promoção</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.promotions.index') }}" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Título da promoção"
                        maxlength="120"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">Estado</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Ativas</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inativas</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.promotions.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($promotions->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhuma promoção encontrada"
                    text="Crie a primeira campanha para oferecer créditos extras nas recargas."
                >
                    <a class="btn btn-primary btn-sm mt-2" href="{{ route('admin.promotions.create') }}">Criar promoção</a>
                </x-ui.empty-state>
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Promoções de créditos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Promoção</th>
                            <th scope="col">Regra</th>
                            <th scope="col">Vigência</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($promotions as $promotion)
                            <tr>
                                <td>
                                    <a class="fw-medium" href="{{ route('admin.promotions.edit', $promotion) }}">
                                        {{ $promotion->title }}
                                    </a>
                                    @if ($promotion->description)
                                        <div class="form-text">{{ \Illuminate\Support\Str::limit($promotion->description, 90) }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $promotion->headline() }}</td>
                                <td class="text-nowrap">
                                    {{ $promotion->starts_at?->format('d/m/Y H:i') ?? 'Sem início' }}
                                    <div class="form-text">
                                        {{ $promotion->ends_at?->format('d/m/Y H:i') ?? 'Sem término' }}
                                    </div>
                                </td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$promotion->statusKey()"
                                        :label="$promotion->statusLabel()"
                                    />
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.promotions.edit', $promotion) }}">
                                            Editar
                                        </a>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.promotions.status', $promotion) }}"
                                            data-confirm="{{ $promotion->is_active
                                                ? 'Inativar esta promoção? Ela deixa de aparecer no site e de conceder bônus em novas compras. Pagamentos já confirmados são preservados.'
                                                : 'Ativar esta promoção? O bônus volta a ser oferecido na home e na carteira.' }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $promotion->is_active ? 0 : 1 }}">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :class="$promotion->is_active ? 'text-danger' : ''"
                                            >
                                                {{ $promotion->is_active ? 'Inativar' : 'Ativar' }}
                                            </x-ui.button>
                                        </form>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.promotions.destroy', $promotion) }}"
                                            data-confirm="Remover esta promoção? O histórico de pagamentos e os bônus já concedidos permanecem íntegros."
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="ghost" size="sm" class="text-danger">
                                                Remover
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>{{ $promotions->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
