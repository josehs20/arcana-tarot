<x-layouts.panel variant="admin" title="{{ $label }} | Conteúdo do site">
    <x-layouts.page-header
        :title="$label"
        subtitle="Edite os textos da seção “{{ $key }}” exibida na página inicial."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.index') }}">Voltar ao conteúdo</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('home') }}{{ $sectionAnchor ? '#'.$sectionAnchor : '' }}" target="_blank" rel="noopener">
                Ver no site
            </a>
        </x-slot>
    </x-layouts.page-header>

    @unless ($isCustomized)
        <div class="alert alert-info" role="status">
            Esta seção ainda usa o conteúdo padrão do sistema. Ao salvar, uma versão própria será criada e passará a ser exibida no site.
        </div>
    @endunless

    @if (filled($hint))
        <div class="alert alert-info" role="status">{{ $hint }}</div>
    @endif

    <form
        method="POST"
        action="{{ route('admin.site.sections.update', ['key' => $key]) }}"
        enctype="multipart/form-data"
    >
        @csrf
        @method('PATCH')

        <div class="row g-4">
            <div class="col-xl-8">
                <x-ui.card>
                    <x-ui.card-header>Textos da seção</x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.field
                            name="eyebrow"
                            label="Texto de apoio"
                            :value="$section['eyebrow']"
                            maxlength="80"
                            help="Linha curta acima do título. Ex.: “Consulta de tarot online”."
                        />

                        <x-ui.field
                            name="title"
                            label="Título"
                            :value="$section['title']"
                            maxlength="120"
                            required
                        />

                        <x-ui.field
                            name="subtitle"
                            label="Subtítulo"
                            :value="$section['subtitle']"
                            maxlength="200"
                        />

                        <x-ui.textarea
                            name="body"
                            label="Texto principal"
                            :value="$section['body']"
                            rows="5"
                            maxlength="2000"
                            help="Aceita texto simples. Quebras de linha são preservadas."
                        />
                    </x-ui.card-body>
                </x-ui.card>

                <x-ui.card class="mt-4">
                    <x-ui.card-header>Chamadas para ação</x-ui.card-header>
                    <x-ui.card-body>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="primary_cta_label"
                                    label="Botão principal"
                                    :value="$section['primary_cta_label']"
                                    maxlength="60"
                                    help="Deixe vazio para não exibir."
                                />
                                <x-ui.field
                                    name="primary_cta_target"
                                    label="Destino do botão principal"
                                    :value="old('primary_cta_target', $ctaTargets['primary'])"
                                    maxlength="255"
                                    list="site-targets"
                                    help="Rota interna, âncora (#como-funciona) ou URL https."
                                />
                            </div>

                            <div class="col-md-6">
                                <x-ui.field
                                    name="secondary_cta_label"
                                    label="Botão secundário"
                                    :value="$section['secondary_cta_label']"
                                    maxlength="60"
                                    help="Deixe vazio para não exibir."
                                />
                                <x-ui.field
                                    name="secondary_cta_target"
                                    label="Destino do botão secundário"
                                    :value="old('secondary_cta_target', $ctaTargets['secondary'])"
                                    maxlength="255"
                                    list="site-targets"
                                    help="Rota interna, âncora (#como-funciona) ou URL https."
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
                    </x-ui.card-body>
                </x-ui.card>
            </div>


            <div class="col-xl-4">
                <x-ui.card>
                    <x-ui.card-header>Publicação</x-ui.card-header>
                    <x-ui.card-body>
                        <x-ui.field
                            name="sort_order"
                            type="number"
                            label="Ordem de exibição"
                            :value="old('sort_order', $section['sort_order'])"
                            min="0"
                            max="999"
                            help="Menores aparecem primeiro na home."
                        />

                        <div class="form-check form-switch mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="is_published"
                                name="is_published"
                                value="1"
                                @checked(old('is_published', $section['is_published']))
                            >
                            <label class="form-check-label" for="is_published">
                                Exibir esta seção na home
                            </label>
                        </div>

                        <x-ui.button type="submit" variant="primary" class="w-100">
                            Salvar seção
                        </x-ui.button>

                        @if ($section['updated_at'])
                            <p class="form-text text-center mt-3 mb-0">
                                Última atualização em
                                {{ $section['updated_at']->timezone(config('app.timezone'))->format('d/m/Y') }}.
                            </p>
                        @endif
                    </x-ui.card-body>
                </x-ui.card>

                <x-ui.card class="mt-4">
                    <x-ui.card-header>Imagem da seção</x-ui.card-header>
                    <x-ui.card-body>
                        @if ($section['image_url'])
                            <img
                                src="{{ $section['image_url'] }}"
                                alt="{{ $section['image_alt'] ?? 'Imagem atual da seção' }}"
                                class="img-fluid rounded border mb-3"
                            >
                        @else
                            <p class="small text-body-secondary border rounded-3 p-3 mb-3">
                                Nenhuma imagem personalizada. A seção usa apenas os textos e o layout padrão.
                            </p>
                        @endif

                        <label class="form-label" for="image">Nova imagem</label>
                        <input
                            class="form-control @error('image') is-invalid @enderror"
                            type="file"
                            id="image"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                        >
                        <div class="form-text">JPEG, PNG ou WebP, até {{ (int) config('site.max_image_kb', 2048) }} KB.</div>
                        @error('image')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        @if ($imagePath)
                            <div class="form-check mt-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="remove_image"
                                    name="remove_image"
                                    value="1"
                                    @checked(old('remove_image'))
                                >
                                <label class="form-check-label small" for="remove_image">Remover imagem atual</label>
                            </div>
                        @endif

                        <div class="mt-3">
                            <x-ui.field
                                name="image_alt"
                                label="Descrição da imagem (alt)"
                                :value="$section['image_alt']"
                                maxlength="160"
                                help="Importante para acessibilidade e SEO."
                            />
                        </div>
                    </x-ui.card-body>
                </x-ui.card>

                <x-ui.card class="mt-4">
                    <x-ui.card-header>Referência</x-ui.card-header>
                    <x-ui.card-body class="small text-body-secondary">
                        <p class="mb-2">Chave técnica: <code>{{ $key }}</code></p>
                        <p class="mb-0">
                            {{ $section['is_customized'] ? 'Conteúdo personalizado e salvo no banco.' : 'Usando o conteúdo padrão de config/site.php.' }}
                        </p>
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    </form>
</x-layouts.panel>
