<x-layouts.panel variant="admin" title="Perguntas frequentes | Conteúdo do site">
    <x-layouts.page-header
        title="Perguntas frequentes"
        subtitle="As perguntas ativas substituem integralmente a lista padrão exibida na home."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.index') }}">Voltar ao conteúdo</a>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.site.faqs.create') }}">Nova pergunta</a>
        </x-slot>
    </x-layouts.page-header>

    @if ($faqs->isEmpty())
        <div class="row g-4">
            <div class="col-xl-8">
                <x-ui.card>
                    <x-ui.card-header>Nenhuma pergunta personalizada</x-ui.card-header>
                    <x-ui.card-body>
                        <div class="empty-state">
                            <div class="empty-state-icon" aria-hidden="true">?</div>
                            <p class="empty-state-title">A home usa as perguntas padrão do sistema</p>
                            <p class="empty-state-text">
                                Enquanto nenhuma pergunta for criada aqui, o site exibe {{ count($defaultFaqs) }} perguntas
                                padrão. Ao criar a primeira, apenas as perguntas cadastradas abaixo serão exibidas.
                            </p>
                            <a class="btn btn-primary btn-sm" href="{{ route('admin.site.faqs.create') }}">Criar primeira pergunta</a>
                        </div>
                    </x-ui.card-body>
                </x-ui.card>
            </div>

            <div class="col-xl-4">
                <x-ui.card>
                    <x-ui.card-header>Perguntas padrão</x-ui.card-header>
                    <x-ui.card-body>
                        <ol class="small text-body-secondary ps-3 mb-0 d-grid gap-2">
                            @foreach ($defaultFaqs as $default)
                                <li>{{ $default['question'] ?? '—' }}</li>
                            @endforeach
                        </ol>
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>
    @else
        <x-ui.card>
            <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>Perguntas cadastradas</span>
                <span class="badge badge-info">{{ $faqs->count() }} pergunta(s)</span>
            </x-ui.card-header>
            <x-ui.card-body>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Pergunta</th>
                                <th scope="col">Situação</th>
                                <th scope="col">Ordem</th>
                                <th scope="col" class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($faqs as $faq)
                                <tr>
                                    <td>
                                        <span class="fw-semibold">{{ $faq->question }}</span>
                                        <div class="small text-body-secondary">
                                            {{ \Illuminate\Support\Str::limit($faq->answer, 110) }}
                                        </div>
                                    </td>
                                    <td>
                                        <x-ui.status-badge
                                            :status="$faq->is_active ? 'online' : 'inactive'"
                                            :label="$faq->is_active ? 'Publicada' : 'Oculta'"
                                        />
                                    </td>
                                    <td>{{ $faq->sort_order }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a
                                                class="btn btn-ghost btn-sm"
                                                href="{{ route('admin.site.faqs.edit', $faq) }}"
                                            >
                                                Editar
                                            </a>
                                            <form
                                                method="POST"
                                                action="{{ route('admin.site.faqs.destroy', $faq) }}"
                                                data-confirm="Remover a pergunta “{{ $faq->question }}”? Esta ação não pode ser desfeita."
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
            </x-ui.card-body>
        </x-ui.card>
    @endif
</x-layouts.panel>
