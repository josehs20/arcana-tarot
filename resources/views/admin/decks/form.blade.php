@php($editing = $deck->exists)

<x-layouts.panel
    variant="admin"
    :title="($editing ? 'Editar baralho' : 'Novo baralho')"
>
    <x-layouts.page-header
        :title="$editing ? 'Editar baralho' : 'Novo baralho'"
        :subtitle="$editing
            ? 'Atualize as informações do baralho. Inativar preserva cartas, imagens e histórico.'
            : 'Cadastre o baralho antes de adicionar as cartas que serão usadas nas consultas.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.index') }}">Voltar aos baralhos</a>
            @if ($editing)
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.decks.cards.index', $deck) }}">Ver cartas</a>
            @endif
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $editing ? route('admin.decks.update', $deck) : route('admin.decks.store') }}"
                    >
                        @csrf
                        @if ($editing)
                            @method('PATCH')
                        @endif

                        <x-ui.field
                            name="name"
                            label="Nome do baralho"
                            :value="$deck->name"
                            placeholder="Ex.: Tarot de Marselha"
                            maxlength="120"
                            required
                        />

                        <x-ui.field
                            name="slug"
                            label="Identificador (slug)"
                            :value="$deck->slug"
                            placeholder="tarot-de-marselha"
                            maxlength="120"
                            help="Identificador único, com letras minúsculas, números e hífens. Não pode ser repetido."
                            required
                        />

                        <x-ui.textarea
                            name="description"
                            label="Descrição (opcional)"
                            :value="$deck->description"
                            rows="4"
                            maxlength="2000"
                            help="Uma breve apresentação do baralho para a administração."
                        />

                        <div class="mb-3">
                            <label class="form-label required" for="is_active">Estado</label>
                            <select
                                class="form-select @error('is_active') is-invalid @enderror"
                                id="is_active"
                                name="is_active"
                                required
                            >
                                <option value="1" @selected((string) old('is_active', $deck->is_active ? '1' : '0') === '1')>
                                    Ativo — disponível para o seletor
                                </option>
                                <option value="0" @selected((string) old('is_active', $deck->is_active ? '1' : '0') === '0')>
                                    Inativo — oculto para novos envios
                                </option>
                            </select>
                            @error('is_active')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary">
                                {{ $editing ? 'Salvar baralho' : 'Criar baralho' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.decks.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>Como funciona</x-ui-card-header>
                <x-ui.card-body>
                    <ul class="panel-list list-unstyled mb-0">
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O baralho precisa estar ativo, e a carta também, para aparecer no seletor de novas mensagens.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Inativar não apaga registros nem imagens. O histórico continua íntegro.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O identificador é único em toda a plataforma e não deve ser alterado sem necessidade.</span>
                        </li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
