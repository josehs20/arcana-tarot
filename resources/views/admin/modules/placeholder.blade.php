<x-layouts.panel variant="admin" :title="$title . ' | Arcana Tarot'">
    <x-layouts.page-header :title="$title" subtitle="Módulo em preparação.">
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card>
        <x-ui.card-body>
            <x-ui.empty-state
                icon="◇"
                :title="$title . ' em breve'"
                :text="$description"
            />

            <div class="mx-auto mt-4" style="max-width: 40rem;">
                <h2 class="h6">O que virá nesta etapa</h2>
                <ul class="panel-list mb-0">
                    @foreach ($upcoming as $item)
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card-body>
    </x-ui.card>
</x-layouts.panel>
