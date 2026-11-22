<x-layouts.panel variant="admin" title="Produtos">
    <x-layouts.page-header
        title="Produtos"
        subtitle="Vitrine da home. O estoque não é controlado: cada produto ativo entrega os créditos indicados após a confirmação do pagamento pelo provedor."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.products.create') }}">Novo produto</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.products.index') }}" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Título do produto"
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
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.products.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($products->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="✦"
                    title="Nenhum produto encontrado"
                    text="Cadastre o primeiro produto para exibir a vitrine na home."
                >
                    <a class="btn btn-primary btn-sm mt-2" href="{{ route('admin.products.create') }}">Cadastrar produto</a>
                </x-ui.empty-state>
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Produtos da vitrine</caption>
                    <thead>
                        <tr>
                            <th scope="col">Produto</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Preço</th>
                            <th scope="col">Vendas</th>
                            <th scope="col">Ordem</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        @if ($product->imageUrl())
                                            <img
                                                class="product-thumb"
                                                src="{{ $product->imageUrl() }}"
                                                alt=""
                                                loading="lazy"
                                            >
                                        @else
                                            <span class="product-thumb product-thumb--empty" aria-hidden="true">✦</span>
                                        @endif
                                        <div>
                                            <a class="fw-medium" href="{{ route('admin.products.edit', $product) }}">
                                                {{ $product->title }}
                                            </a>
                                            @if ($product->description)
                                                <div class="form-text">{{ $product->summary(90) }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-nowrap">{{ $product->creditsLabel() }}</td>
                                <td class="text-nowrap">{{ $product->priceLabel() }}</td>
                                <td>{{ $product->purchases_count }}</td>
                                <td>{{ $product->sort_order }}</td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$product->is_active ? 'active' : 'inactive'"
                                        :label="$product->is_active ? 'Ativo' : 'Inativo'"
                                    />
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.products.edit', $product) }}">
                                            Editar
                                        </a>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.products.status', $product) }}"
                                            data-confirm="{{ $product->is_active
                                                ? 'Inativar este produto? Ele deixa de aparecer na vitrine e não pode ser comprado.'
                                                : 'Ativar este produto? Ele volta a aparecer na vitrine da home.' }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}">
                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                {{ $product->is_active ? 'Inativar' : 'Ativar' }}
                                            </x-ui.button>
                                        </form>
                                        @if ($product->purchases_count === 0)
                                            <form
                                                method="POST"
                                                action="{{ route('admin.products.destroy', $product) }}"
                                                data-confirm="Remover este produto da vitrine? Esta ação não pode ser desfeita."
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="ghost" size="sm" class="text-danger">
                                                    Remover
                                                </x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>{{ $products->links() }}</x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

