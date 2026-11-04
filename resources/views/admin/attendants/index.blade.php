<x-layouts.panel variant="admin" title="Atendentes | Arcana Tarot">
    <x-layouts.page-header
        title="Atendentes"
        subtitle="Cadastro dos profissionais e preferências do atendimento, como o som que avisa quando um cliente está aguardando."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.modalities.index') }}">Modalidades</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O som de chamada é reproduzido no navegador do atendente quando um cliente entra na fila.
        A sirene é o padrão; os demais toques são sintetizados no navegador e o MP3 personalizado
        pode ser enviado por atendente. O toque escolhido não altera o aceite, a cobrança ou o funcionamento do chat.
    </x-ui.alert>

    @error('request_sound')
        <x-ui.alert type="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @error('request_sound_file')
        <x-ui.alert type="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.attendants.index') }}" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="q">Buscar atendente</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $search }}"
                        maxlength="100"
                        placeholder="Nome, e-mail ou nome público"
                    >
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.attendants.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($attendants->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="☾"
                    title="Nenhum atendente encontrado"
                    text="Ajuste a busca ou habilite perfis profissionais."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Atendentes e sons de chamada configurados</caption>
                    <thead>
                        <tr>
                            <th scope="col">Atendente</th>
                            <th scope="col">Presença</th>
                            <th scope="col">Conta</th>
                            <th scope="col">Som de chamada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendants as $user)
                            @php
                                $profile = $user->attendantProfile;
                                $currentSound = $profile->effectiveRequestSound();
                                $currentSoundUrl = $profile->requestSoundUrl();
                                $fieldId = 'request_sound_'.$user->id;
                                $fileFieldId = 'request_sound_file_'.$user->id;
                            @endphp
                            <tr id="atendente-{{ $user->id }}">
                                <td>
                                    <span class="fw-medium">{{ $profile->display_name ?: $user->name }}</span>
                                    <div class="form-text">{{ $user->name }} · {{ $user->email }}</div>
                                </td>
                                <td>
                                    <x-ui.status-badge :status="$profile->availability->value" />
                                </td>
                                <td>
                                    @if ($user->is_active)
                                        <x-ui.status-badge status="online" label="Ativa" />
                                    @else
                                        <x-ui.status-badge status="inactive" />
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.attendants.sound.update', $user) }}">
                                        @csrf
                                        @method('PATCH')

                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <label class="visually-hidden" for="{{ $fieldId }}">
                                                Som de chamada de {{ $user->name }}
                                            </label>
                                            <select
                                                class="form-select form-select-sm w-auto @error('request_sound') is-invalid @enderror"
                                                id="{{ $fieldId }}"
                                                name="request_sound"
                                                required
                                            >
                                                @foreach ($sounds as $sound)
                                                    <option value="{{ $sound->value }}" @selected($currentSound === $sound)>
                                                        {{ $sound->label() }}
                                                    </option>
                                                @endforeach

                                                @if ($currentSoundUrl)
                                                    <option value="{{ $customSound->value }}" @selected($currentSound === $customSound)>
                                                        {{ $customSound->label() }}
                                                    </option>
                                                @endif
                                            </select>

                                            <button
                                                type="button"
                                                class="btn btn-ghost btn-sm"
                                                data-preview-request-sound="{{ $fieldId }}"
                                                data-preview-request-sound-url="{{ $currentSoundUrl }}"
                                            >
                                                Ouvir
                                            </button>

                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                Salvar som
                                            </x-ui.button>
                                        </div>

                                        <div class="form-text">
                                            {{ $currentSound->description() }}
                                            @if ($currentSoundUrl)
                                                O MP3 personalizado está disponível para este atendente.
                                            @endif
                                        </div>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route('admin.attendants.sound.upload', $user) }}"
                                        enctype="multipart/form-data"
                                        class="mt-3"
                                    >
                                        @csrf

                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <label class="visually-hidden" for="{{ $fileFieldId }}">
                                                MP3 de chamada de {{ $user->name }}
                                            </label>
                                            <input
                                                type="file"
                                                class="form-control form-control-sm w-auto"
                                                id="{{ $fileFieldId }}"
                                                name="request_sound_file"
                                                accept=".mp3,audio/mpeg"
                                                required
                                            >
                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                {{ $currentSoundUrl ? 'Substituir MP3' : 'Enviar MP3' }}
                                            </x-ui.button>
                                        </div>

                                        <div class="form-text">
                                            MP3 de até {{ (int) config('attendant.request_sound_max_seconds') }} segundos e
                                            {{ (int) config('attendant.request_sound_max_kb') }} KB. O arquivo é selecionado
                                            automaticamente como som personalizado.
                                        </div>
                                    </form>

                                    @if ($currentSoundUrl)
                                        <form
                                            method="POST"
                                            action="{{ route('admin.attendants.sound.destroy', $user) }}"
                                            class="mt-2"
                                            data-confirm="Remover o MP3 personalizado deste atendente?"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <audio
                                                    controls
                                                    preload="none"
                                                    src="{{ $currentSoundUrl }}"
                                                    style="height: 32px; max-width: 240px;"
                                                ></audio>
                                                <x-ui.button type="submit" variant="ghost" size="sm">
                                                    Remover MP3
                                                </x-ui.button>
                                            </div>
                                        </form>
                                    @endif
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
