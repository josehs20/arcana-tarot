<x-layouts.panel variant="admin" :title="'Consulta #' . $consultation->id . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Consulta #' . $consultation->id"
        :subtitle="$consultation->client->name . ' × ' . ($consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name)"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.index') }}">Voltar para consultas</a>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.safety') }}">Segurança do chat</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O histórico da conversa é dado sensível: cada abertura desta tela é registrada na auditoria com
        autor, consulta e endereço IP. Os trechos com dado pessoal aparecem mascarados.
    </x-ui.alert>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card
                label="Duração"
                :value="$consultation->durationLabel() ?? '—'"
                :hint="$consultation->started_at ? 'Início ' . $consultation->started_at->format('d/m H:i') : 'Ainda não iniciada'"
            />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card
                label="Total cobrado"
                :value="$consultation->totalChargedLabel()"
                :hint="(int) $consultation->billed_minutes . ' min × ' . ($consultation->pricePerMinuteLabel() ?? '—')"
                tone="gold"
            />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card
                label="Comissão líquida"
                :value="\App\Support\Money::format($consultation->commissionNetCents())"
                :hint="$consultation->commission_rate_bps !== null ? \App\Support\Rate::formatBps($consultation->commission_rate_bps) : 'Taxa não registrada'"
            />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.stat-card
                label="Ocorrências"
                :value="$incidents->count()"
                :hint="$openIncidents . ' em aberto'"
                :tone="$incidents->isEmpty() ? 'success' : 'warning'"
                href="#ocorrencias"
            />
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Participantes</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row small mb-0">
                        <dt class="col-sm-4 text-ivory-muted fw-normal">Cliente</dt>
                        <dd class="col-sm-8">
                            <span class="fw-medium">{{ $consultation->client->name }}</span>
                            <div class="form-text">{{ $consultation->client->email }}</div>
                            <x-ui.badge :variant="$consultation->client->is_active ? 'success' : 'secondary'">
                                {{ $consultation->client->is_active ? 'Conta ativa' : 'Conta inativa' }}
                            </x-ui.badge>
                        </dd>

                        <dt class="col-sm-4 text-ivory-muted fw-normal">Atendente</dt>
                        <dd class="col-sm-8">
                            <span class="fw-medium">
                                {{ $consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name }}
                            </span>
                            <div class="form-text">{{ $consultation->attendant->email }}</div>
                            <x-ui.badge :variant="$consultation->attendant->is_active ? 'success' : 'secondary'">
                                {{ $consultation->attendant->is_active ? 'Conta ativa' : 'Conta inativa' }}
                            </x-ui.badge>
                        </dd>

                        <dt class="col-sm-4 text-ivory-muted fw-normal">Modalidade</dt>
                        <dd class="col-sm-8">{{ $consultation->modalityLabel() }}</dd>

                        <dt class="col-sm-4 text-ivory-muted fw-normal">Status</dt>
                        <dd class="col-sm-8">
                            <x-ui.status-badge
                                :status="$consultation->status->value"
                                :label="$consultation->status->label()"
                            />
                        </dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Tempos e cobrança</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row small mb-0">
                        <dt class="col-7 text-ivory-muted fw-normal">Solicitada em</dt>
                        <dd class="col-5 mb-2">{{ $consultation->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Início</dt>
                        <dd class="col-5 mb-2">{{ $consultation->started_at?->format('d/m/Y H:i') ?? 'Não iniciada' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Última atividade</dt>
                        <dd class="col-5 mb-2">{{ $consultation->last_activity_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Encerrada em</dt>
                        <dd class="col-5 mb-2">{{ $consultation->ended_at?->format('d/m/Y H:i') ?? 'Em aberto' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Motivo do encerramento</dt>
                        <dd class="col-5 mb-2">{{ $consultation->ended_reason?->label() ?? '—' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Preço por minuto</dt>
                        <dd class="col-5 mb-2">{{ $consultation->pricePerMinuteLabel() ?? '—' }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Minutos cobrados</dt>
                        <dd class="col-5 mb-2">{{ (int) $consultation->billed_minutes }}</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Total cobrado</dt>
                        <dd class="col-5 mb-0 fw-semibold">{{ $consultation->totalChargedLabel() }}</dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card id="ocorrencias" class="mb-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Ocorrências de dado pessoal</h2>
        </x-ui.card-header>
        <x-ui.card-body>
            @if ($incidents->isEmpty())
                <x-ui.alert type="success" :dismissible="false" class="mb-0">
                    Nenhuma tentativa de envio de dado pessoal ou contato externo foi registrada nesta consulta.
                </x-ui.alert>
            @else
                <p class="form-text">
                    Cada linha é uma mensagem recusada pelo servidor. O texto original fica criptografado no
                    banco; aqui aparece apenas o trecho mascarado.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Quando</th>
                                <th scope="col">Participante</th>
                                <th scope="col">Categoria</th>
                                <th scope="col">Detectado</th>
                                <th scope="col">Situação</th>
                                <th scope="col">Trecho mascarado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incidents as $incident)
                                <tr>
                                    <td class="text-nowrap">
                                        <a href="#ocorrencia-{{ $incident->id }}">
                                            {{ $incident->created_at?->format('d/m/Y H:i') ?? '—' }}
                                        </a>
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $incident->user?->name ?? 'Participante removido' }}
                                    </td>
                                    <td class="text-nowrap">{{ $incident->categoryLabel() }}</td>
                                    <td>
                                        {{ $incident->detectedLabels() === [] ? '—' : implode(', ', $incident->detectedLabels()) }}
                                    </td>
                                    <td class="text-nowrap">
                                        <x-ui.status-badge
                                            :status="$incident->isOpen() ? 'pending' : 'approved'"
                                            :label="$incident->statusLabel()"
                                        />
                                    </td>
                                    <td><code>{{ $incident->masked_excerpt }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card-body>
    </x-ui.card>

    <div
        data-admin-history
        data-history-url="{{ route('admin.consultations.entries', $consultation) }}"
        data-history-last-message="{{ $lastMessageId }}"
        data-history-last-incident="{{ $lastIncidentId }}"
        data-history-live="{{ $isLive ? '1' : '0' }}"
        data-history-interval="{{ (int) data_get($pollIntervals, 'panel_interval_ms', 3000) }}"
        data-history-hidden-interval="{{ (int) data_get($pollIntervals, 'panel_hidden_interval_ms', 15000) }}"
        data-history-backoff="{{ (int) data_get($pollIntervals, 'max_backoff_ms', 30000) }}"
    >
        <x-ui.card>
            <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Histórico da conversa</h2>
                <span class="badge bg-secondary" data-history-status role="status" aria-live="polite">
                    {{ $isLive ? 'Atualização automática ativa' : 'Somente leitura' }}
                </span>
            </x-ui.card-header>
            <x-ui.card-body>
                <p class="form-text">
                    Mais recentes primeiro. Trechos com dado pessoal aparecem mascarados e as mensagens
                    recusadas pelo servidor ficam destacadas na própria linha do tempo.
                </p>

                @if ($entries === [])
                    <x-ui.empty-state
                        title="Nenhum registro"
                        text="Esta consulta ainda não tem mensagens nem tentativas bloqueadas."
                    />
                @endif

                <ol
                    class="chat-timeline admin-history-timeline {{ $entries === [] ? 'd-none' : '' }}"
                    data-history-timeline
                    aria-label="Histórico da consulta"
                >
                    @include('admin.consultations.partials.history-entries', ['entries' => $entries])
                </ol>

                @if ($messages->hasPages())
                    <div class="mt-3">{{ $messages->links() }}</div>
                @endif
            </x-ui.card-body>
        </x-ui.card>
    </div>
</x-layouts.panel>
