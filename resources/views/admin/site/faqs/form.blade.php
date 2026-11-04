<x-layouts.panel variant="admin" title="Pergunta frequente | Conteúdo do site">
    @php
        $isEdit = $faq->exists;
    @endphp

    <x-layouts.page-header
        :title="$isEdit ? 'Editar pergunta' : 'Nova pergunta'"
        subtitle="Perguntas ativas aparecem no acordeão da home, respeitando a ordem definida."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.site.faqs.index') }}">Voltar às perguntas</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-xl-8">
            <x-ui.card>
                <x-ui.card-header>Conteúdo da pergunta</x-ui.card-header>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $isEdit ? route('admin.site.faqs.update', $faq) : route('admin.site.faqs.store') }}"
                    >
                        @csrf
                        @if ($isEdit)
                            @method('PATCH')
                        @endif

                        <x-ui.field
                            name="question"
                            label="Pergunta"
                            :value="old('question', $faq->question)"
                            maxlength="200"
                            required
                            help="Escreva como a pessoa perguntaria. Ex.: “Como funciona a cobrança?”."
                        />

                        <x-ui.textarea
                            name="answer"
                            label="Resposta"
                            :value="old('answer', $faq->answer)"
                            rows="6"
                            maxlength="2000"
                            required
                            help="Texto simples, direto e sem promessas de resultado."
                        />

                        <div class="row g-3 align-items-end">
                            <div class="col-sm-4">
                                <x-ui.field
                                    name="sort_order"
                                    type="number"
                                    label="Ordem"
                                    :value="old('sort_order', $faq->sort_order)"
                                    min="0"
                                    max="999"
                                    help="Menores aparecem primeiro."
                                />
                            </div>

                            <div class="col-sm-8">
                                <div class="form-check form-switch mb-3">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="is_active"
                                        name="is_active"
                                        value="1"
                                        @checked(old('is_active', $faq->is_active ?? true))
                                    >
                                    <label class="form-check-label" for="is_active">
                                        Exibir esta pergunta na home
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <x-ui.button type="submit" variant="primary">
                                {{ $isEdit ? 'Salvar pergunta' : 'Criar pergunta' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.site.faqs.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>

            @if ($isEdit)
                <x-ui.card class="mt-4">
                    <x-ui.card-header>Remover pergunta</x-ui.card-header>
                    <x-ui.card-body class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <p class="small text-body-secondary mb-0">
                            A remoção é definitiva. Para tirar a pergunta do site sem apagar, desative-a acima.
                        </p>
                        <form
                            method="POST"
                            action="{{ route('admin.site.faqs.destroy', $faq) }}"
                            data-confirm="Remover a pergunta “{{ $faq->question }}”? Esta ação não pode ser desfeita."
                        >
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-danger btn-sm" type="submit">Remover pergunta</button>
                        </form>
                    </x-ui.card-body>
                </x-ui.card>
            @endif
        </div>

        <div class="col-xl-4">
            <x-ui.card>
                <x-ui.card-header>Boas práticas</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="list-unstyled d-grid gap-3 mb-0 small">
                        <li>Uma pergunta por item, com resposta objetiva de até poucos parágrafos.</li>
                        <li>Evite dados pessoais, promessas de resultado ou linguagem sensacionalista.</li>
                        <li>Desativar mantém o histórico de auditoria e permite reativar depois.</li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
