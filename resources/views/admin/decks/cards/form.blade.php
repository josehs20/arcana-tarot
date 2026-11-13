@php
    $editing = $card->exists;
    $hasStoredImage = $editing && $card->hasStoredImage();
@endphp

<x-layouts.panel
    variant="admin"
    :title="($editing ? 'Editar carta' : 'Nova carta') . ' — ' . $deck->name"
>
    <x-layouts.page-header
        :title="$editing ? 'Editar carta' : 'Nova carta'"
        :subtitle="'Baralho: ' . $deck->name . '. A imagem frontal é obrigatória e será armazenada em local privado.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.cards.index', $deck) }}">Voltar às cartas</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.edit', $deck) }}">Editar baralho</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $editing
                            ? route('admin.decks.cards.update', [$deck, $card])
                            : route('admin.decks.cards.store', $deck) }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        @if ($editing)
                            @method('PATCH')
                        @endif

                        <div class="row">
                            <div class="col-md-8">
                                <x-ui.field
                                    name="name"
                                    label="Nome da carta"
                                    :value="$card->name"
                                    placeholder="Ex.: A Estrela"
                                    maxlength="120"
                                    required
                                />
                            </div>
                            <div class="col-md-4">
                                <x-ui.field
                                    name="sort_order"
                                    label="Ordem de exibição"
                                    type="number"
                                    :value="$card->sort_order ?? 1"
                                    min="0"
                                    max="99999"
                                    help="Menor número aparece primeiro."
                                    required
                                />
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label {{ $hasStoredImage ? '' : 'required' }}" for="image">
                                Imagem frontal
                            </label>
                            <x-ui.image-input
                                name="image"
                                :profile="\App\Support\Images\ImageProfile::TarotCard"
                                :required="! $hasStoredImage"
                            >
                                O servidor valida o conteúdo real do arquivo e gera um nome aleatório.
                                @if ($hasStoredImage)
                                    Envie um novo arquivo apenas se quiser substituir a imagem atual; a anterior é preservada no histórico.
                                @endif
                            </x-ui.image-input>
                        </div>

                        @if ($hasStoredImage)
                            <div class="mb-4">
                                <p class="form-label mb-2">Imagem atual</p>
                                <a href="{{ $card->imageUrl() }}" target="_blank" rel="noopener">
                                    <img
                                        class="tarot-card-preview"
                                        src="{{ $card->imageUrl() }}"
                                        alt="Imagem frontal atual de {{ $card->name }}"
                                    >
                                </a>
                                <div class="form-text">
                                    A pré-visualização usa uma rota autorizada e não expõe o caminho físico do storage.
                                </div>
                            </div>
                        @endif

                        <div class="row">
                            <div class="col-md-6">
                                <x-ui.textarea
                                    name="meaning_upright"
                                    label="Significado normal (opcional)"
                                    :value="$card->meaning_upright"
                                    rows="4"
                                    maxlength="5000"
                                />
                            </div>
                            <div class="col-md-6">
                                <x-ui.textarea
                                    name="meaning_reversed"
                                    label="Significado invertido (opcional)"
                                    :value="$card->meaning_reversed"
                                    rows="4"
                                    maxlength="5000"
                                />
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label required" for="is_active">Estado</label>
                            <select
                                class="form-select @error('is_active') is-invalid @enderror"
                                id="is_active"
                                name="is_active"
                                required
                            >
                                <option value="1" @selected((string) old('is_active', $card->is_active ? '1' : '0') === '1')>
                                    Ativa — disponível no seletor quando o baralho também estiver ativo
                                </option>
                                <option value="0" @selected((string) old('is_active', $card->is_active ? '1' : '0') === '0')>
                                    Inativa — preservada no histórico, oculta para novos envios
                                </option>
                            </select>
                            @error('is_active')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary">
                                {{ $editing ? 'Salvar carta' : 'Criar carta' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.decks.cards.index', $deck) }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>Segurança e histórico</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="panel-list list-unstyled mb-0">
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Apenas JPEG, PNG e WebP são aceitos. SVG e executáveis são rejeitados.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O nome do arquivo é gerado no servidor; o caminho do storage nunca é enviado ao navegador.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Substituir a imagem cria um novo arquivo. O anterior não é apagado para não quebrar mensagens antigas.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Prefira inativar em vez de excluir. O catálogo não oferece exclusão de cartas usadas no histórico.</span>
                        </li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>

