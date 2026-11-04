<x-layouts.panel variant="admin" title="Modalidades dos atendentes | Arcana Tarot">
    <x-layouts.page-header
        title="Modalidades dos atendentes"
        subtitle="Defina quais modalidades cada profissional pode atender. Texto é sempre oferecido; áudio e vídeo exigem habilitação."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.index') }}">Tarifas por modalidade</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        @foreach ($modalities as $modality)
            <div class="col-md-4">
                <div class="modality-price-summary">
                    <span class="modality-price-summary__label">
                        <x-ui.icon :name="$modality->icon()" width="16" height="16" />
                        {{ $modality->label() }}
                    </span>
                    <span class="modality-price-summary__value">
                        {{ $labelsByModality[$modality->value] ?? 'Não configurado' }}
                        @if ($labelsByModality[$modality->value] !== null)
                            <small>/ minuto</small>
                        @endif
                    </span>
                    <span class="form-text">
                        @if ($catalog->isEnabled($modality))
                            Infraestrutura disponível
                        @else
                            Desativada na infraestrutura
                        @endif
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    @error('offered_modalities')
        <x-ui.alert type="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.modalities.index') }}" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="q">Buscar atendente</label>
                    <input class="form-control" type="search" id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Nome, e-mail ou nome público">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.modalities.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($attendants->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state icon="☾" title="Nenhum atendente encontrado" text="Ajuste a busca ou habilite perfis profissionais." />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Modalidades oferecidas por atendente</caption>
                    <thead>
                        <tr>
                            <th scope="col">Atendente</th>
                            <th scope="col">Presença</th>
                            <th scope="col">Modalidades oferecidas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendants as $user)
                            @php
                                $profile = $user->attendantProfile;
                            @endphp
                            <tr>
                                <td>
                                    <span class="fw-medium">{{ $profile->display_name ?: $user->name }}</span>
                                    <div class="form-text">{{ $user->name }} · {{ $user->email }}</div>
                                </td>
                                <td>
                                    <x-ui.status-badge :status="$profile->availability->value" />
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.modalities.update', $user) }}">
                                        @csrf
                                        @method('PATCH')

                                        <div class="d-flex flex-wrap gap-3">
                                            @foreach ($modalities as $modality)
                                                @php
                                                    $enabled = $catalog->isEnabled($modality);
                                                    $fieldId = 'modality-'.$user->id.'-'.$modality->value;
                                                    $checked = $profile->offersModality($modality) || ! $modality->requiresMedia();
                                                @endphp
                                                <div class="form-check modality-checkbox">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="offered_modalities[]"
                                                        value="{{ $modality->value }}"
                                                        id="{{ $fieldId }}"
                                                        @checked($checked)
                                                        @disabled(! $enabled)
                                                    >
                                                    <label class="form-check-label" for="{{ $fieldId }}">
                                                        {{ $modality->label() }}
                                                        <span class="form-text d-block">
                                                            @if (! $enabled)
                                                                Indisponível na infraestrutura
                                                            @elseif ($modality->requiresMedia())
                                                                Chamada WebRTC
                                                            @else
                                                                Chat e cartas
                                                            @endif
                                                        </span>
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>

                                        <x-ui.button type="submit" variant="ghost" size="sm" class="mt-2">
                                            Salvar modalidades
                                        </x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $attendants->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
