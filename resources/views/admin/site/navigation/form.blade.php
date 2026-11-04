<x-layouts.panel variant="admin" title="Link de navegação | Conteúdo do site">
    @php
        $isEdit = $link->exists;
    @endphp

    <x-layouts.page-header
        :title="$isEdit ? 'Editar link' : 'Novo link'"
        subtitle="Escolha onde o link aparece, o destino permitido e se abre em nova aba."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.navigation.index') }}">Voltar à navegação</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-xl-8">
            <x-ui.card>
                <x-ui.card-header>Dados do link</x-ui.card-header>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $isEdit ? route('admin.site.navigation.update', $link) : route('admin.site.navigation.store') }}"
                    >
                        @csrf
                        @if ($isEdit)
                            @method('PATCH')
                        @endif

                        <div class="row g-3">
                            <div class="col-md-7">
                                <x-ui.field
                                    name="label"
                                    label="Texto do link"
                                    :value="old('label', $link->label)"
                                    maxlength="40"
                                    required
                                    help="Curto e direto. Ex.: “Como funciona”."
                                />
                            </div>

                            <div class="col-md-5">
                                <label class="form-label required" for="group">Onde aparece</label>
                                <select
                                    class="form-select @error('group') is-invalid @enderror"
                                    id="group"
                                    name="group"
                                    required
                                >
                                    @foreach ($groups as $group)
                                        <option
                                            value="{{ $group->value }}"
                                            @selected(old('group', $link->group?->value) === $group->value)
                                        >
                                            {{ $group->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('group')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-5">
                                <label class="form-label required" for="target_type">Tipo de destino</label>
                                <select
                                    class="form-select @error('target_type') is-invalid @enderror"
                                    id="target_type"
                                    name="target_type"
                                    required
                                >
                                    @foreach (\App\Enums\SiteLinkType::cases() as $type)
                                        <option
                                            value="{{ $type->value }}"
                                            @selected(old('target_type', $link->target_type?->value) === $type->value)
                                        >
                                            {{ $type->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('target_type')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-7">
                                <x-ui.field
                                    name="target"
                                    label="Destino"
                                    :value="old('target', $link->target)"
                                    maxlength="255"
                                    list="site-targets"
                                    required
                                />
                            </div>
                        </div>

                        <datalist id="site-targets">
                            @foreach ($allowedRoutes as $route)
                                <option value="{{ $route }}"></option>
                            @endforeach
                            @foreach ($allowedAnchors as $anchor)
                                <option value="#{{ $anchor }}"></option>
                            @endforeach
                        </datalist>

                        <details class="small text-body-secondary mb-4">
                            <summary class="mb-2">Destinos permitidos</summary>
                            <p class="mb-1"><strong>Página interna:</strong> {{ implode(', ', $allowedRoutes) }}</p>
                            <p class="mb-1"><strong>Âncora na home:</strong> #{{ implode(', #', $allowedAnchors) }}</p>
                            <p class="mb-0"><strong>Endereço externo:</strong> apenas URLs iniciadas por <code>https://</code>.</p>
                        </details>

                        <div class="row g-3 align-items-end">
                            <div class="col-sm-4">
                                <x-ui.field
                                    name="sort_order"
                                    type="number"
                                    label="Ordem"
                                    :value="old('sort_order', $link->sort_order)"
                                    min="0"
                                    max="999"
                                />
                            </div>

                            <div class="col-sm-8">
                                <div class="d-grid gap-2 mb-3">
                                    <div class="form-check form-switch">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            id="is_active"
                                            name="is_active"
                                            value="1"
                                            @checked(old('is_active', $link->is_active ?? true))
                                        >
                                        <label class="form-check-label" for="is_active">Link ativo</label>
                                    </div>

                                    <div class="form-check form-switch">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            id="opens_in_new_tab"
                                            name="opens_in_new_tab"
                                            value="1"
                                            @checked(old('opens_in_new_tab', $link->opens_in_new_tab))
                                        >
                                        <label class="form-check-label" for="opens_in_new_tab">Abrir em nova aba</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <x-ui.button type="submit" variant="primary">
                                {{ $isEdit ? 'Salvar link' : 'Criar link' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.site.navigation.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-xl-4">
            <x-ui.card>
                <x-ui.card-header>Sobre a navegação</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="list-unstyled d-grid gap-3 mb-0 small">
                        <li>
                            Cada grupo (navbar ou rodapé) usa os links personalizados quando existir ao menos um.
                        </li>
                        <li>
                            Links inativos permanecem salvos, mas não são exibidos no site.
                        </li>
                        <li>
                            Endereços externos abrem sempre com <code>rel="noopener"</code> por segurança.
                        </li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
