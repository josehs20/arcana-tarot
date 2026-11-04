<x-layouts.panel variant="admin" title="Conteúdo do site | Arcana Tarot">
    @php
        $publishedCount = $sections->where('is_published', true)->count();
        $customizedCount = $sections->where('is_customized', true)->count();
        $labelFor = fn (string $key): string => $sectionLabels[$key] ?? \Illuminate\Support\Str::headline($key);
    @endphp

    <x-layouts.page-header
        title="Conteúdo do site"
        subtitle="Edite a identidade, os textos da home, a navegação e as perguntas frequentes. As alterações aparecem imediatamente no site público."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('home') }}" target="_blank" rel="noopener">
                Ver site público
            </a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.navigation.index') }}">Navegação</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.site.faqs.index') }}">Perguntas frequentes</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-ui.card class="h-100">
                <x-ui.card-body>
                    <p class="text-body-secondary small text-uppercase mb-1">Seções publicadas</p>
                    <p class="h3 mb-0">
                        {{ $publishedCount }}
                        <span class="fs-6 text-body-secondary">/ {{ $sections->count() }}</span>
                    </p>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-6 col-xl-3">
            <x-ui.card class="h-100">
                <x-ui.card-body>
                    <p class="text-body-secondary small text-uppercase mb-1">Conteúdo personalizado</p>
                    <p class="h3 mb-0">
                        {{ $customizedCount }}
                        <span class="fs-6 text-body-secondary">/ {{ $sections->count() }}</span>
                    </p>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-6 col-xl-3">
            <x-ui.card class="h-100">
                <x-ui.card-body>
                    <p class="text-body-secondary small text-uppercase mb-1">Links ativos</p>
                    <p class="h3 mb-0">{{ $navigationCount }}</p>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-6 col-xl-3">
            <x-ui.card class="h-100">
                <x-ui.card-body>
                    <p class="text-body-secondary small text-uppercase mb-1">Perguntas frequentes</p>
                    <p class="h3 mb-0">{{ $faqCount }}</p>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Seções da home</span>
            <span class="small text-body-secondary">A ordem de exibição é definida em cada seção.</span>
        </x-ui.card-header>
        <x-ui.card-body>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xxl-3 g-3">
                @foreach ($sections as $section)
                    <div class="col">
                        <div class="border rounded-3 h-100 p-3 d-flex flex-column gap-2">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <h2 class="h6 mb-1">{{ $labelFor($section['key']) }}</h2>
                                    <code class="small">{{ $section['key'] }}</code>
                                </div>
                                <x-ui.status-badge
                                    :status="$section['is_published'] ? 'online' : 'inactive'"
                                    :label="$section['is_published'] ? 'Publicada' : 'Oculta'"
                                />
                            </div>

                            <p class="small text-body-secondary mb-0">
                                {{ \Illuminate\Support\Str::limit($section['title'], 90) }}
                            </p>

                            <div class="d-flex flex-wrap align-items-center gap-2 mt-auto pt-2">
                                <span class="badge {{ $section['is_customized'] ? 'badge-info' : 'badge-secondary' }}">
                                    {{ $section['is_customized'] ? 'Personalizada' : 'Texto padrão' }}
                                </span>
                                <span class="small text-body-secondary">Ordem {{ $section['sort_order'] }}</span>
                                <a
                                    class="btn btn-ghost btn-sm ms-auto"
                                    href="{{ route('admin.site.sections.edit', ['key' => $section['key']]) }}"
                                >
                                    Editar
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card-body>
    </x-ui.card>

    <div class="row g-4">
        <div class="col-xl-8">
            <x-ui.card id="identidade">
                <x-ui.card-header>Identidade do site</x-ui.card-header>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ route('admin.site.identity.update') }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        @method('PATCH')

                        <x-ui.field
                            name="site_name"
                            label="Nome do site"
                            :value="$identity['name']"
                            maxlength="80"
                            required
                            help="Aparece na navbar, no rodapé, no título das páginas e nos e-mails."
                        />

                        <x-ui.textarea
                            name="site_description"
                            label="Descrição curta"
                            :value="$identity['description']"
                            rows="3"
                            maxlength="300"
                            required
                            help="Usada na meta description e nos blocos de apresentação."
                        />

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="logo">Logotipo</label>

                                @if ($identity['has_logo'])
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <img
                                            src="{{ $identity['logo_url'] }}"
                                            alt="Logotipo atual"
                                            class="rounded border bg-white p-1"
                                            style="height: 48px; width: auto;"
                                        >
                                        <div class="form-check mb-0">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="remove_logo"
                                                name="remove_logo"
                                                value="1"
                                                @checked(old('remove_logo'))
                                            >
                                            <label class="form-check-label small" for="remove_logo">Remover atual</label>
                                        </div>
                                    </div>
                                @else
                                    <p class="form-text mt-0">Nenhum logotipo personalizado. O nome em texto é usado.</p>
                                @endif

                                <input
                                    class="form-control @error('logo') is-invalid @enderror"
                                    type="file"
                                    id="logo"
                                    name="logo"
                                    accept="image/jpeg,image/png,image/webp"
                                >
                                <div class="form-text">JPEG, PNG ou WebP, até {{ (int) config('site.max_image_kb', 2048) }} KB.</div>
                                @error('logo')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="favicon">Favicon</label>

                                @if ($identity['has_favicon'])
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <img
                                            src="{{ $identity['favicon_url'] }}"
                                            alt="Favicon atual"
                                            class="rounded border bg-white p-1"
                                            style="height: 32px; width: 32px;"
                                        >
                                        <div class="form-check mb-0">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="remove_favicon"
                                                name="remove_favicon"
                                                value="1"
                                                @checked(old('remove_favicon'))
                                            >
                                            <label class="form-check-label small" for="remove_favicon">Remover atual</label>
                                        </div>
                                    </div>
                                @else
                                    <p class="form-text mt-0">Nenhum favicon personalizado. O ícone padrão é usado.</p>
                                @endif

                                <input
                                    class="form-control @error('favicon') is-invalid @enderror"
                                    type="file"
                                    id="favicon"
                                    name="favicon"
                                    accept="image/jpeg,image/png,image/webp"
                                >
                                <div class="form-text">Recomendado: PNG quadrado, até {{ (int) config('site.max_image_kb', 2048) }} KB.</div>
                                @error('favicon')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <x-ui.button type="submit" variant="primary">Salvar identidade</x-ui.button>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-xl-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>Como o conteúdo funciona</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="list-unstyled d-grid gap-3 mb-0">
                        <li class="small">
                            Enquanto uma seção não for editada, o site usa o texto padrão do sistema.
                        </li>
                        <li class="small">
                            Despublicar uma seção a remove da home sem apagar o conteúdo salvo.
                        </li>
                        <li class="small">
                            Destinos de links aceitam apenas rotas internas, âncoras da home ou URLs https.
                        </li>
                        <li class="small">
                            Toda alteração fica registrada na auditoria com autor e data.
                        </li>
                    </ul>

                    <div class="d-grid gap-2 mt-4">
                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.navigation.index') }}">
                            Gerenciar navegação
                        </a>
                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.faqs.index') }}">
                            Gerenciar perguntas frequentes
                        </a>
                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.audit.index') }}">
                            Ver auditoria
                        </a>
                    </div>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>

