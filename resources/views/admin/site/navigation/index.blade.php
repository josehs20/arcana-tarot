<x-layouts.panel variant="admin" title="Navegação | Conteúdo do site">
    <x-layouts.page-header
        title="Navegação"
        subtitle="Defina os links da navbar e do rodapé. Os grupos sem links personalizados continuam exibindo o padrão do sistema."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.index') }}">Voltar ao conteúdo</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.site.navigation.create') }}">Novo link</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="alert alert-info" role="note">
        Ao criar o primeiro link de um grupo, o padrão daquele grupo deixa de ser exibido por completo. Destinos aceitos: rotas internas, âncoras da home ou URLs <code>https</code>.
    </div>

    <div class="d-grid gap-4">
        @foreach ($groups as $group)
            @php
                $links = $linksByGroup[$group->value] ?? collect();
                $defaultLinks = (array) ($defaults[$group->value] ?? []);
            @endphp

            <x-ui.card>
                <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span>{{ $group->label() }}</span>
                    <span class="badge {{ $links->isEmpty() ? 'badge-secondary' : 'badge-info' }}">
                        {{ $links->isEmpty() ? 'Usando padrão' : $links->count().' link(s) personalizado(s)' }}
                    </span>
                </x-ui.card-header>
                <x-ui.card-body>
                    @if ($links->isEmpty())
                        <p class="small text-body-secondary">
                            Nenhum link personalizado neste grupo. O site exibe atualmente:
                        </p>
                        <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
                            @foreach ($defaultLinks as $default)
                                <li class="badge badge-secondary fw-normal">
                                    {{ $default['label'] ?? '—' }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Link</th>
                                        <th scope="col">Destino</th>
                                        <th scope="col">Comportamento</th>
                                        <th scope="col">Ordem</th>
                                        <th scope="col" class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($links as $link)
                                        <tr>
                                            <td>
                                                <span class="fw-semibold">{{ $link->label }}</span>
                                                <div class="small text-body-secondary">{{ $link->target_type->label() }}</div>
                                            </td>
                                            <td><code class="small">{{ $link->target }}</code></td>
                                            <td>
                                                <x-ui.status-badge
                                                    :status="$link->is_active ? 'online' : 'inactive'"
                                                    :label="$link->is_active ? 'Ativo' : 'Inativo'"
                                                />
                                                <div class="small text-body-secondary mt-1">
                                                    {{ $link->opens_in_new_tab ? 'Nova aba' : 'Mesma aba' }}
                                                </div>
                                            </td>
                                            <td>{{ $link->sort_order }}</td>
                                            <td class="text-end">
                                                <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                    <a
                                                        class="btn btn-ghost btn-sm"
                                                        href="{{ route('admin.site.navigation.edit', $link) }}"
                                                    >
                                                        Editar
                                                    </a>
                                                    <form
                                                        method="POST"
                                                        action="{{ route('admin.site.navigation.destroy', $link) }}"
                                                        data-confirm="Remover o link “{{ $link->label }}” da navegação?"
                                                    >
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="btn btn-danger btn-sm" type="submit">Remover</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card-body>
            </x-ui.card>
        @endforeach
    </div>
</x-layouts.panel>
