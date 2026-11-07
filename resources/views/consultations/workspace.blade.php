@php
    $status = $consultation->status;
    $modality = $consultation->modality;
    $attendantName = $consultation->attendant->attendantProfile?->display_name ?? $consultation->attendant->name;
    $counterparty = $isClient ? $attendantName : $consultation->client->name;
@endphp

{{--
    Tela dedicada de atendimento.

    Concentra o essencial do atendimento em andamento: identificação das
    partes, números oficiais calculados no servidor e a conversa em tempo real.
    Os valores exibidos nos cartões usam `data-metric`, preenchidos pelo módulo
    `resources/js/consultation-workspace.js` a cada limite de minuto e a cada
    transição de estado — sempre a partir de `consultations.metrics`. O tempo
    restante (`data-consultation-countdown`) também nasce no servidor e é apenas
    decrementado no navegador entre uma atualização e outra.
--}}
<x-layouts.panel variant="panel" :title="'Atendimento #' . $consultation->id . ' | Arcana Tarot'">
    <div
        data-consultation-workspace
        data-consultation-id="{{ $consultation->id }}"
        data-current-user-id="{{ auth()->id() }}"
        data-metrics-url="{{ route('consultations.metrics', $consultation) }}"
        data-presence-url="{{ route('consultations.presence.heartbeat', $consultation) }}"
        data-leave-url="{{ route('consultations.presence.leave', $consultation) }}"
        @can('end', $consultation) data-end-url="{{ route('consultations.end', $consultation) }}" @endcan
        data-show-url="{{ route('consultations.show', $consultation) }}"
        data-status="{{ $status->value }}"
        data-navigation-warning-seconds="{{ $safety['navigation_warning_seconds'] }}"
        data-inactivity-timeout-seconds="{{ $safety['inactivity_timeout_minutes'] * 60 }}"
        data-activity-ping-seconds="{{ $safety['activity_ping_seconds'] }}"
        @if ($creditsUrl) data-credits-url="{{ $creditsUrl }}" @endif
    >
        <x-layouts.page-header
            :title="'Atendimento #' . $consultation->id"
            :subtitle="$isClient
                ? 'Você está em atendimento com ' . $counterparty . '.'
                : 'Você está atendendo ' . $counterparty . '.'"
        >
            <x-slot name="actions">
                <span id="consultation-workspace-status" class="d-inline-flex align-items-center gap-2">
                    <x-ui.badge variant="gold">
                        <x-ui.icon :name="$modality->icon()" width="13" height="13" />
                        {{ $modality->label() }}
                    </x-ui.badge>
                    <x-ui.status-badge :status="$status->value" :label="$status->label()" />
                </span>
                <a class="btn btn-ghost btn-sm" href="{{ route('consultations.show', $consultation) }}">
                    Histórico da consulta
                </a>
            </x-slot>
        </x-layouts.page-header>

        <div class="row g-3 g-xl-4 mb-4">
            <div class="col-sm-6 col-xl-3">
                {{-- O dinheiro substituiu o relógio: o saldo cai a cada minuto
                     iniciado, inclusive o que ainda está sendo cobrado. --}}
                <x-ui.stat-card
                    :label="$isClient ? 'Saldo restante' : 'Saldo do cliente'"
                    icon="credit-card"
                    tone="info"
                >
                    <span class="stat-card__value" data-metric="balance_label">{{ $metrics['balance_label'] ?? '—' }}</span>
                    <span class="stat-card__hint" data-metric="balance_explanation">{{ $metrics['balance_explanation'] ?? 'Saldo da carteira.' }}</span>
                </x-ui.stat-card>
            </div>

            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card label="Valor cobrado" icon="wallet" tone="success">
                    <span class="stat-card__value" data-metric="charged_label">{{ $metrics['charged_label'] }}</span>
                    <span class="stat-card__hint" data-metric="charged_explanation">{{ $metrics['charged_explanation'] }}</span>
                </x-ui.stat-card>
            </div>

            <div class="col-sm-6 col-xl-3">
                <x-ui.stat-card label="Tempo restante" icon="activity" tone="warning">
                    {{-- O servidor manda os segundos; o navegador só decrementa. --}}
                    <span class="stat-card__value">
                        <span
                            data-consultation-countdown
                            data-remaining-seconds="{{ $metrics['remaining_seconds'] ?? '' }}"
                        >{{ $metrics['remaining_label'] ?? '—' }}</span>
                    </span>
                    <span class="stat-card__hint" data-metric="remaining_explanation">
                        {{ $metrics['remaining_explanation'] ?? 'Saldo insuficiente para o próximo minuto.' }}
                    </span>
                </x-ui.stat-card>
            </div>

            <div class="col-sm-6 col-xl-3">
                @if ($isAttendant)
                    <x-ui.stat-card label="Sua comissão" icon="percent" tone="gold">
                        <span class="stat-card__value" data-metric="commission_label">{{ $metrics['commission_label'] ?? '—' }}</span>
                        <span class="stat-card__hint" data-metric="commission_explanation">{{ $metrics['commission_explanation'] ?? 'Calculada no servidor ao longo do atendimento.' }}</span>
                    </x-ui.stat-card>
                @else
                    <x-ui.stat-card
                        label="Solicitada em"
                        :value="$metrics['requested_at_label']"
                        hint="Horário em que o pedido foi enviado."
                        icon="calendar"
                    />
                @endif
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                @if ($canViewMessages)
                    @include('consultations.partials.chat')
                @elseif ($consultation->requiresCall())
                    @include('consultations.partials.call')
                @endif
            </div>

            <div class="col-lg-4">
                @include('consultations.partials.parties')

                <x-ui.card>
                    <x-ui.card-header>
                        <h2 class="h6 mb-0">Ações</h2>
                    </x-ui.card-header>
                    <x-ui.card-body>
                        @if ($isClient && $creditsUrl)
                            <div class="mb-4 pb-3 border-bottom border-secondary-subtle">
                                <p class="stat-card__label mb-1">Saldo de créditos</p>
                                <p class="h5 mb-2" data-credit-balance>
                                    {{ $creditBalance['balance_label'] ?? '—' }}
                                </p>
                                <p class="form-text mt-0 mb-2">
                                    O atendimento continua ativo e sendo cobrado enquanto você estiver fora do chat.
                                </p>
                                <a
                                    class="btn btn-secondary w-100"
                                    href="{{ $creditsUrl }}"
                                    data-allow-leave
                                    data-consultation-credits-link
                                >
                                    Comprar créditos
                                </a>
                            </div>
                        @endif

                        @can('end', $consultation)
                            {{--
                                Encerrar é a saída pretendida: `data-allow-leave` dispensa o
                                aviso nativo de navegação (beforeunload) no envio do formulário.
                            --}}
                            <form
                                method="POST"
                                action="{{ route('consultations.end', $consultation) }}"
                                data-allow-leave
                            >
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" variant="danger" block>Encerrar consulta</x-ui.button>
                            </form>
                        @endcan

                        <p class="form-text mt-3 mb-0">
                            A cobrança é por minuto iniciado e calculada no servidor. O encerramento
                            interrompe a contagem imediatamente.
                        </p>
                    </x-ui.card-body>
                </x-ui.card>
            </div>
        </div>

        @if ($isAttendant)
            {{--
                Aviso após tempo sem interação; o relógio oficial continua no
                servidor. Só o atendente recebe este modal: o cliente é avisado
                quando o saldo está acabando (`consultation-low-balance-warning`)
                e dois alertas simultâneos travariam a conversa sem que ele
                pudesse resolver nada.
            --}}
            <div
                class="modal fade"
                id="consultation-inactivity-warning"
                tabindex="-1"
                aria-labelledby="consultation-inactivity-warning-title"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
                data-consultation-inactivity-warning
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="consultation-inactivity-warning-title">
                                Você ainda está no atendimento?
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Não identificamos interação há {{ $safety['navigation_warning_seconds'] }} segundos.
                                O atendimento continua ativo e sendo cobrado.
                            </p>
                            <p class="form-text mb-0">
                                Sem interação de nenhum participante por {{ $safety['inactivity_timeout_minutes'] }} minutos,
                                a consulta é encerrada automaticamente pelo servidor. <strong>Sair do chat</strong>
                                encerra o atendimento agora e interrompe a cobrança.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-ghost" data-consultation-leave-confirm>
                                Sair do chat
                            </button>
                            <button type="button" class="btn btn-primary" data-consultation-continue>
                                Continuar no atendimento
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($isClient && $creditsUrl)
            {{--
                Aviso de saldo baixo, exclusivo do cliente.

                O limite chega pronto do servidor em
                `data-remaining-threshold-seconds` e o módulo
                `resources/js/consultation-workspace.js` compara com o contador
                regressivo calculado em `consultations.metrics`
                (`remaining_seconds`). Nada é decidido no navegador: o aviso é
                apenas informativo e o encerramento por saldo insuficiente
                continua sendo do servidor.
            --}}
            <div
                class="modal fade"
                id="consultation-low-balance-warning"
                tabindex="-1"
                aria-labelledby="consultation-low-balance-warning-title"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
                data-consultation-low-balance-warning
                data-remaining-threshold-seconds="{{ $safety['low_balance_warning_seconds'] }}"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="consultation-low-balance-warning-title">
                                Seu saldo está acabando
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Restam menos de
                                {{ (int) ceil($safety['low_balance_warning_seconds'] / 60) }} minutos
                                de saldo para continuar este atendimento.
                            </p>
                            <p class="form-text mb-0">
                                A cobrança é por minuto iniciado. Quando o saldo não cobrir o próximo minuto,
                                o servidor encerra a consulta automaticamente — comprar créditos agora mantém
                                a conversa ativa.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">
                                Continuar no atendimento
                            </button>
                            <a class="btn btn-primary" href="{{ $creditsUrl }}" data-allow-leave>
                                Comprar créditos
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Aviso ao navegar para fora do chat sem encerrar a consulta. --}}
        <div
            class="modal fade"
            id="consultation-navigation-warning"
            tabindex="-1"
            aria-labelledby="consultation-navigation-warning-title"
            aria-hidden="true"
            data-consultation-navigation-warning
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="consultation-navigation-warning-title">
                            Sair do atendimento?
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            Ao sair desta tela, o atendimento continua ativo e os créditos continuam sendo
                            descontados até o encerramento.
                        </p>
                        <p class="form-text mb-0">
                            Use <strong>Encerrar consulta</strong> antes de sair para interromper a cobrança agora.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">
                            Ficar no atendimento
                        </button>
                        <button type="button" class="btn btn-primary" data-consultation-navigation-confirm>
                            Sair mesmo assim
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.panel>

