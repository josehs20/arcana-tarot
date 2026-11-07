{{--
    Detalhe de um disparo: o texto exatamente como foi enviado e a situação de
    cada destinatário. O botão de reenvio só aparece quando há falhas.
--}}
<x-layouts.panel variant="admin" title="Disparo de e-mails | Arcana Tarot">
    <x-layouts.page-header title="Detalhes do disparo" :subtitle="$campaign->subject">
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.emails.index') }}">Voltar para disparos</a>
        </x-slot>
    </x-layouts.page-header>

    @if (session('success'))
        <x-ui.alert type="success" :dismissible="false" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    @error('campaign')
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card label="Destinatários" :value="$campaign->recipients_count" icon="users" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card label="Enviados" :value="$campaign->sent_count" icon="send" tone="success" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card label="Falhas" :value="$campaign->failed_count" icon="alert-triangle" :tone="$campaign->hasFailures() ? 'danger' : 'default'" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card label="Na fila" :value="$pendingCount" icon="clock" tone="warning" />
        </div>
    </div>

    <x-ui.card class="mb-4">
        <x-ui.card-header class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <h2 class="h6 mb-1">Mensagem enviada</h2>
                <p class="form-text mb-0">
                    Criada por {{ $campaign->creator?->name ?? '—' }}
                    em {{ $campaign->created_at?->format('d/m/Y H:i') ?? '—' }} ·
                    <x-ui.status-badge :status="$campaign->statusBadge()" :label="$campaign->statusLabel()" />
                </p>
            </div>

            @if ($campaign->hasFailures())
                <form
                    method="POST"
                    action="{{ route('admin.emails.resend', $campaign) }}"
                    data-confirm="Reenviar {{ $campaign->failed_count }} e-mail(s) que falharam?"
                >
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm" class="text-gold">
                        <x-ui.icon name="refresh" width="16" height="16" />
                        Reenviar falhas ({{ $campaign->failed_count }})
                    </x-ui.button>
                </form>
            @endif
        </x-ui.card-header>

        <x-ui.card-body>
            <p class="bulk-email-preview__label">Assunto</p>
            <p class="bulk-email-preview__subject">{{ $campaign->subject }}</p>

            <p class="bulk-email-preview__label">Corpo</p>
            <p class="bulk-email-preview__body">{{ $campaign->body }}</p>

            @if ($campaign->filters)
                <p class="form-text mb-0">
                    Filtros usados na seleção:
                    @foreach ($campaign->filters as $key => $value)
                        <code>{{ $key }}: {{ $value }}</code>@if (! $loop->last) · @endif
                    @endforeach
                </p>
            @endif
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        <x-ui.card-header>
            <h2 class="h6 mb-1">Destinatários</h2>
            <p class="form-text mb-0">
                O envio é feito em segundo plano: atualize a página para acompanhar o andamento.
            </p>
        </x-ui.card-header>

        @if ($recipients->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum destinatário"
                    text="Este disparo não possui destinatários registrados."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Destinatário</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Enviado em</th>
                            <th scope="col">Erro</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recipients as $recipient)
                            <tr>
                                <td class="bulk-email-recipient-cell">
                                    <span class="d-block">{{ $recipient->name ?? '—' }}</span>
                                    <span class="form-text d-block mb-0">{{ $recipient->email }}</span>
                                </td>
                                <td>
                                    <x-ui.status-badge
                                        :status="$recipient->status->badgeStatus()"
                                        :label="$recipient->status->label()"
                                    />
                                </td>
                                <td class="text-nowrap">{{ $recipient->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="bulk-email-recipient-cell">
                                    @if ($recipient->error)
                                        <span class="text-danger small">{{ $recipient->error }}</span>
                                    @else
                                        <span class="text-ivory-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $recipients->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
