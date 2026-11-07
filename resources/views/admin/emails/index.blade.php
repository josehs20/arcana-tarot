{{--
    Disparo de e-mails em massa.

    A página tem dois formulários independentes: o de filtros (GET, recarrega a
    lista de usuários) e o de envio (POST, cria a campanha e enfileira os jobs).
    A tabela de destinatários fica dentro do formulário de envio; a paginação e
    os filtros recarregam a página, então a seleção vale para a página atual.
--}}
<x-layouts.panel variant="admin" title="Disparo de e-mails | Arcana Tarot">
    <x-layouts.page-header
        title="Disparo de e-mails"
        subtitle="Selecione os usuários, escreva a mensagem e acompanhe o envio no histórico."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.audit.index') }}">Auditoria</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    @if (session('success'))
        <x-ui.alert type="success" :dismissible="false" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
            <div>
                <strong>Não foi possível concluir a ação.</strong>
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </x-ui.alert>
    @endif

    @if ($mailerIsLog || $queueIsSync)
        <x-ui.alert type="info" :dismissible="false" class="mb-4">
            <div>
                <strong>Ambiente de teste</strong>
                <ul class="mb-0 mt-1">
                    @if ($mailerIsLog)
                        <li>O mailer atual é <code>log</code>: as mensagens vão para o log, não para a caixa do destinatário.</li>
                    @endif
                    @if ($queueIsSync)
                        <li>A fila é <code>sync</code>: os envios acontecem durante a requisição, sem worker.</li>
                    @endif
                </ul>
            </div>
        </x-ui.alert>
    @endif

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.emails.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome ou e-mail"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="role">Papel</label>
                    <select class="form-select" id="role" name="role">
                        <option value="">Todos</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>
                                {{ match ($role->value) {
                                    'admin' => 'Administrador',
                                    'attendant' => 'Atendente',
                                    'client' => 'Cliente',
                                    default => $role->value,
                                } }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Ativas</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inativas</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.emails.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <form
        method="POST"
        action="{{ route('admin.emails.store') }}"
        data-bulk-email-form
        data-bulk-email-sample-name="Maria"
    >
        @csrf

        <div class="row g-4">
            <div class="col-xl-7">
                <x-ui.card class="h-100">
                    <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <h2 class="h6 mb-1">Destinatários</h2>
                            <p class="form-text mb-0">
                                <strong data-bulk-email-count>0</strong> selecionado(s) nesta página ·
                                limite de {{ $maxRecipients }} por disparo.
                            </p>
                        </div>
                        <div class="form-check mb-0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="select-all-recipients"
                                data-bulk-email-select-all
                            >
                            <label class="form-check-label" for="select-all-recipients">Selecionar página</label>
                        </div>
                    </x-ui.card-header>

                    @if ($recipients->isEmpty())
                        <x-ui.card-body>
                            <x-ui.empty-state
                                icon="◇"
                                title="Nenhum usuário encontrado"
                                text="Ajuste os filtros acima para listar contas com e-mail cadastrado."
                            />
                        </x-ui.card-body>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col"><span class="visually-hidden">Selecionar</span></th>
                                        <th scope="col">Usuário</th>
                                        <th scope="col">Papel</th>
                                        <th scope="col">Conta</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recipients as $recipient)
                                        <tr>
                                            <td>
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="recipients[]"
                                                    id="recipient-{{ $recipient->id }}"
                                                    value="{{ $recipient->id }}"
                                                    data-bulk-email-recipient
                                                    @checked(collect(old('recipients', []))->contains($recipient->id))
                                                >
                                            </td>
                                            <td>
                                                <label class="d-block mb-0" for="recipient-{{ $recipient->id }}">
                                                    <span class="d-block">{{ $recipient->name }}</span>
                                                    <span class="form-text d-block mb-0">{{ $recipient->email }}</span>
                                                </label>
                                            </td>
                                            <td>
                                                {{ match ($recipient->role->value) {
                                                    'admin' => 'Administrador',
                                                    'attendant' => 'Atendente',
                                                    'client' => 'Cliente',
                                                    default => $recipient->role->value,
                                                } }}
                                            </td>
                                            <td>
                                                @if ($recipient->is_active)
                                                    <x-ui.status-badge status="online" label="Ativa" />
                                                @else
                                                    <x-ui.status-badge status="inactive" />
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <x-ui.card-footer>
                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                <span class="form-text mb-0">
                                    {{ $recipients->total() }} usuário(s) na lista
                                    @if ($recipients->count() > 0 && $recipients->total() > $recipients->count())
                                        · exibindo {{ $recipients->firstItem() }}–{{ $recipients->lastItem() }}
                                    @endif
                                </span>
                                {{ $recipients->links() }}
                            </div>
                        </x-ui.card-footer>
                    @endif
                </x-ui.card>
            </div>

            <div class="col-xl-5">
                <x-ui.card class="h-100">
                    <x-ui.card-header>
                        <h2 class="h6 mb-1">Mensagem</h2>
                        <p class="form-text mb-0">O texto abaixo é enviado para cada destinatário selecionado.</p>
                    </x-ui.card-header>

                    <x-ui.card-body>
                        <x-ui.field
                            name="subject"
                            label="Assunto"
                            :value="$template['subject']"
                            maxlength="150"
                            required
                            help="Assunto fixo para todos os destinatários."
                        />

                        <x-ui.textarea
                            name="body"
                            label="Corpo do e-mail"
                            :value="$template['body']"
                            rows="14"
                            maxlength="10000"
                            required
                        />

                        <p class="form-text mt-n2">
                            Marcadores substituídos no envio:
                            <code>@{{nome}}</code> (primeiro nome),
                            <code>@{{email}}</code> e
                            <code>@{{link}}</code> (endereço do site).
                            O texto é enviado como está — não é interpretado como HTML.
                        </p>

                        <button
                            type="button"
                            class="btn btn-ghost btn-sm"
                            aria-expanded="false"
                            aria-controls="bulk-email-preview"
                            data-bulk-email-preview-toggle
                        >
                            <x-ui.icon name="search" width="16" height="16" />
                            Prévia
                        </button>

                        <div class="bulk-email-preview d-none" id="bulk-email-preview" data-bulk-email-preview>
                            <p class="bulk-email-preview__label">Prévia com um destinatário de exemplo</p>
                            <p class="bulk-email-preview__subject" data-bulk-email-preview-subject></p>
                            <p class="bulk-email-preview__body" data-bulk-email-preview-body></p>
                        </div>
                    </x-ui.card-body>

                    <x-ui.card-footer>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <x-ui.button
                                type="submit"
                                variant="primary"
                                size="sm"
                                data-bulk-email-send
                            >
                                <x-ui.icon name="send" width="16" height="16" />
                                Enviar disparo
                            </x-ui.button>

                            <x-ui.button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                formaction="{{ route('admin.emails.template.update') }}"
                                data-bulk-email-save-template
                            >
                                Salvar como corpo padrão
                            </x-ui.button>
                        </div>

                        <p class="form-text mb-0 mt-2" data-bulk-email-hint>
                            <span data-bulk-email-hint-default>
                                Marque ao menos um destinatário para enviar. O envio entra na fila; o
                                acompanhamento fica no histórico abaixo.
                            </span>
                            <span class="text-danger d-none" data-bulk-email-hint-error>
                                Marque ao menos um destinatário para enviar o disparo.
                            </span>
                        </p>
                    </x-ui.card-footer>
                </x-ui.card>
            </div>
        </div>
    </form>

    <x-ui.card class="mt-4">
        <x-ui.card-header>
            <h2 class="h6 mb-1">Histórico de disparos</h2>
            <p class="form-text mb-0">
                Cada disparo guarda o assunto e o corpo enviados, mesmo que o corpo padrão mude depois.
            </p>
        </x-ui.card-header>

        @if ($campaigns->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum disparo registrado"
                    text="O primeiro envio aparece aqui com o resumo de entregas e falhas."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Enviado em</th>
                            <th scope="col">Assunto</th>
                            <th scope="col">Autor</th>
                            <th scope="col" class="text-center">Destinatários</th>
                            <th scope="col" class="text-center">Enviados</th>
                            <th scope="col" class="text-center">Falhas</th>
                            <th scope="col">Situação</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            <tr>
                                <td class="text-nowrap">{{ $campaign->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="bulk-email-recipient-cell">{{ $campaign->subject }}</td>
                                <td>{{ $campaign->creator?->name ?? '—' }}</td>
                                <td class="text-center">{{ $campaign->recipients_count }}</td>
                                <td class="text-center">{{ $campaign->sent_count }}</td>
                                <td class="text-center">
                                    @if ($campaign->hasFailures())
                                        <span class="text-danger">{{ $campaign->failed_count }}</span>
                                    @else
                                        {{ $campaign->failed_count }}
                                    @endif
                                </td>
                                <td>
                                    <x-ui.status-badge :status="$campaign->statusBadge()" :label="$campaign->statusLabel()" />
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.emails.show', $campaign) }}">
                                        Detalhes
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $campaigns->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
