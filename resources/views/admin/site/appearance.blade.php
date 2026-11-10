<x-layouts.panel variant="admin" title="Cores e fontes | Conteúdo do site">
    <x-layouts.page-header
        title="Cores e fontes"
        subtitle="Personalize a paleta dos temas claro e escuro e escolha as fontes de títulos e textos. As mudanças aparecem imediatamente no site público."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.index') }}">Voltar ao conteúdo</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('home') }}" target="_blank" rel="noopener">Ver site público</a>
        </x-slot>
    </x-layouts.page-header>

    @if ($isCustomized)
        <div class="alert alert-info" role="status">
            O site usa uma paleta personalizada. Use “Restaurar padrão” para voltar ao design original do tema.
        </div>
    @else
        <div class="alert alert-info" role="status">
            O site está com o design padrão. As cores abaixo já mostram os valores atuais do tema; altere o que precisar e salve.
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('admin.site.appearance.update') }}"
        id="appearance-form"
        data-appearance-editor
    >
        @csrf
        @method('PATCH')

        <script type="application/json" data-appearance-config>{!! json_encode(['defaults' => $defaults, 'defaultFonts' => $defaultFonts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

        <div class="row g-4">
            @foreach (['light' => 'Tema claro', 'dark' => 'Tema escuro'] as $theme => $themeLabel)
                <div class="col-xl-6">
                    <x-ui.card class="h-100">
                        <x-ui.card-header>{{ $themeLabel }}</x-ui.card-header>
                        <x-ui.card-body>
                            @foreach ($colorLabels as $token => $label)
                                @php($value = old("colors.{$theme}.{$token}", $colors[$theme][$token] ?? ''))
                                <div class="mb-3 {{ $errors->has("colors.{$theme}.{$token}") ? 'has-error' : '' }}">
                                    <label class="form-label" for="colors-{{ $theme }}-{{ $token }}">{{ $label }}</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input
                                            class="form-control form-control-color {{ $errors->has("colors.{$theme}.{$token}") ? 'is-invalid' : '' }}"
                                            type="color"
                                            id="colors-{{ $theme }}-{{ $token }}"
                                            name="colors[{{ $theme }}][{{ $token }}]"
                                            value="{{ $value }}"
                                            data-theme="{{ $theme }}"
                                            data-token="{{ $token }}"
                                        >
                                        <span class="font-monospace small text-body-secondary" data-hex-for="colors-{{ $theme }}-{{ $token }}">{{ $value }}</span>
                                    </div>
                                    <div class="form-text">Padrão: <span class="font-monospace">{{ $defaults[$theme][$token] ?? '' }}</span></div>
                                    @error("colors.{$theme}.{$token}")
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach
                        </x-ui.card-body>
                    </x-ui.card>
                </div>
            @endforeach
        </div>
        <x-ui.card class="mt-4">
            <x-ui.card-header>Tipografia</x-ui.card-header>
            <x-ui.card-body>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="fonts-display">Fonte de títulos</label>
                        <select
                            class="form-select @error('fonts.display') is-invalid @enderror"
                            id="fonts-display"
                            name="fonts[display]"
                            data-kind="display"
                        >
                            @foreach ($displayFonts as $optionValue => $optionLabel)
                                <option
                                    value="{{ $optionValue }}"
                                    data-stack="{{ config('appearance.fonts.display.'.$optionValue.'.stack') }}"
                                    @selected(old('fonts.display', $fonts['display']) === $optionValue)
                                >{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @error('fonts.display')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="fonts-body">Fonte de textos</label>
                        <select
                            class="form-select @error('fonts.body') is-invalid @enderror"
                            id="fonts-body"
                            name="fonts[body]"
                            data-kind="body"
                        >
                            @foreach ($bodyFonts as $optionValue => $optionLabel)
                                <option
                                    value="{{ $optionValue }}"
                                    data-stack="{{ config('appearance.fonts.body.'.$optionValue.'.stack') }}"
                                    @selected(old('fonts.body', $fonts['body']) === $optionValue)
                                >{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @error('fonts.body')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="form-text mt-2">Apenas fontes empacotadas no site ou fontes do sistema são usadas — nada é carregado de servidores externos.</div>
            </x-ui.card-body>
        </x-ui.card>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <x-ui.button type="submit" variant="primary">Salvar cores e fontes</x-ui.button>
        </div>
    </form>

    @if ($isCustomized)
        <form method="POST" action="{{ route('admin.site.appearance.reset') }}" class="mt-3">
            @csrf
            @method('DELETE')
            <x-ui.button
                type="submit"
                variant="ghost"
                onclick="return confirm('Restaurar o design padrão do tema? A personalização atual será removida.');"
            >
                Restaurar padrão
            </x-ui.button>
        </form>
    @endif
</x-layouts.panel>

