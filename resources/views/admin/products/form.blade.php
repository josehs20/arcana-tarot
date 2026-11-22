@php
    $editing = $product->exists;
    $hasStoredImage = filled($product->image_path);
    $priceValue = $editing
        ? intdiv($product->price_cents, 100).','.str_pad((string) ($product->price_cents % 100), 2, '0', STR_PAD_LEFT)
        : null;
@endphp

<x-layouts.panel
    variant="admin"
    :title="$editing ? 'Editar produto' : 'Novo produto'"
>
    <x-layouts.page-header
        :title="$editing ? 'Editar produto' : 'Novo produto'"
        :subtitle="$editing
            ? 'Alterações valem para novas compras. Pedidos já registrados mantêm título, preço e créditos do momento da compra.'
            : 'Defina o preço e quantos créditos o cliente recebe. O crédito só entra na carteira após a confirmação do pagamento.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.products.index') }}">Voltar aos produtos</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        @if ($editing)
                            @method('PATCH')
                        @endif

                        <x-ui.field
                            name="title"
                            label="Título do produto"
                            :value="$product->title"
                            placeholder="Ex.: Leitura completa"
                            maxlength="120"
                            required
                        />

                        <x-ui.textarea
                            name="description"
                            label="Descrição"
                            :value="$product->description"
                            rows="4"
                            maxlength="2000"
                            help="Texto exibido no card da vitrine. Descreva o que o pacote entrega."
                        />

                        <div class="row g-3">
                            <div class="col-md-4">
                                <x-ui.field
                                    name="credits_amount"
                                    type="number"
                                    label="Créditos entregues"
                                    :value="$product->credits_amount"
                                    min="1"
                                    max="100000"
                                    inputmode="numeric"
                                    required
                                />
                            </div>

                            <div class="col-md-4">
                                <x-ui.field
                                    name="price"
                                    type="text"
                                    label="Preço (R$)"
                                    :value="$priceValue"
                                    placeholder="29,90"
                                    maxlength="20"
                                    inputmode="decimal"
                                    required
                                />
                            </div>

                            <div class="col-md-4">
                                <x-ui.field
                                    name="sort_order"
                                    type="number"
                                    label="Ordem na vitrine"
                                    :value="$product->sort_order ?? 0"
                                    min="0"
                                    max="99999"
                                    inputmode="numeric"
                                    help="Menores aparecem primeiro."
                                />
                            </div>
                        </div>

                        <x-ui.select
                            name="is_active"
                            label="Estado"
                            :value="$product->is_active ? '1' : '0'"
                            :options="[
                                '1' => 'Ativo — aparece na home e pode ser comprado',
                                '0' => 'Inativo — oculto na vitrine',
                            ]"
                            required
                        />

                        @if ($hasStoredImage)
                            <div class="mb-3">
                                <p class="form-label mb-2">Imagem atual</p>
                                <img
                                    class="product-image-preview"
                                    src="{{ $product->imageUrl() }}"
                                    alt="Imagem atual de {{ $product->title }}"
                                >
                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        id="remove_image"
                                        name="remove_image"
                                        value="1"
                                        @checked(old('remove_image'))
                                    >
                                    <label class="form-check-label" for="remove_image">
                                        Remover a imagem atual (sem enviar outra)
                                    </label>
                                </div>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="image">Imagem do produto</label>
                            <x-ui.image-input
                                name="image"
                                :profile="\App\Support\Images\ImageProfile::Product"
                            >
                                {{ $editing ? 'Envie um novo arquivo apenas se quiser substituir a imagem atual.' : 'Opcional. Sem imagem, o card usa o símbolo da marca.' }}
                            </x-ui.image-input>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary">
                                {{ $editing ? 'Salvar produto' : 'Criar produto' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.products.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>Como funciona</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="panel-list list-unstyled mb-0">
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O valor cobrado e a quantidade de créditos são lidos do servidor no momento da compra — o navegador nunca informa preço.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">A compra só é marcada como paga depois que o provedor confirma o pagamento. Webhooks repetidos não creditam duas vezes.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Editar ou inativar não reescreve o histórico: cada venda guarda uma cópia de título, preço e créditos.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Produtos com vendas registradas não podem ser removidos — inative para tirar da vitrine.</span>
                        </li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>

