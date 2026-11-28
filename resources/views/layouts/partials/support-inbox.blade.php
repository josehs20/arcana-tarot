@php
    $supportSummary = app(\App\Support\SupportChat\SupportInbox::class)->summary();
    $supportMode = \App\Support\Realtime\RealtimeMode::current();
    $supportPoll = config('support.polling');
@endphp

{{--
    Central de suporte da administração.

    Um único widget acompanha todas as telas administrativas: a lista de
    conversas, a thread ativa, a presença do operador e o aviso de mensagens
    novas. O canal `support.inbox` só é aberto para administradores ativos; no
    modo polling a mesma lista é relida por HTTP.
--}}
<div
    class="support-inbox"
    data-support-inbox
    data-conversations-url="{{ route('admin.support.conversations.index') }}"
    data-conversation-url="{{ route('admin.support.conversations.show', ['conversation' => '__ID__']) }}"
    data-message-url="{{ route('admin.support.messages.store', ['conversation' => '__ID__']) }}"
    data-read-url="{{ route('admin.support.messages.read', ['conversation' => '__ID__']) }}"
    data-close-url="{{ route('admin.support.conversations.close', ['conversation' => '__ID__']) }}"
    data-presence-url="{{ route('admin.support.presence') }}"
    data-realtime-mode="{{ $supportMode }}"
    data-auto-open="{{ $supportSummary['unread_total'] > 0 ? '1' : '0' }}"
    data-poll-list-ms="{{ (int) $supportPoll['admin_conversations_ms'] }}"
    data-poll-list-backup-ms="{{ (int) $supportPoll['admin_socket_backup_ms'] }}"
    data-poll-messages-ms="{{ (int) $supportPoll['admin_messages_ms'] }}"
    data-poll-messages-backup-ms="{{ (int) $supportPoll['admin_socket_backup_ms'] }}"
    data-presence-interval-ms="{{ (int) config('support.presence_heartbeat_seconds', 45) * 1000 }}"
>
    <button
        type="button"
        class="support-inbox__launcher"
        data-support-inbox-launcher
        aria-controls="support-inbox-panel"
        aria-expanded="false"
        aria-label="Abrir central de suporte"
    >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M4 5h16v11H9l-5 4V5Z" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M8 9h8M8 12h5" stroke-linecap="round"/>
        </svg>
        <span class="support-inbox__launcher-label">Suporte</span>
        <span class="support-inbox__badge" data-support-inbox-badge @if ($supportSummary['unread_total'] === 0) hidden @endif>
            {{ $supportSummary['unread_total'] > 99 ? '99+' : $supportSummary['unread_total'] }}
        </span>
    </button>

    <section
        id="support-inbox-panel"
        class="support-inbox__panel"
        data-support-inbox-panel
        aria-label="Central de suporte"
        hidden
    >
        <header class="support-inbox__header">
            <div>
                <h2 class="support-inbox__title">Central de suporte</h2>
                <p class="support-inbox__summary" data-support-inbox-summary>
                    {{ $supportSummary['open'] }}
                    {{ $supportSummary['open'] === 1 ? 'conversa aberta' : 'conversas abertas' }}
                </p>
            </div>
            <button type="button" class="support-inbox__close" data-support-inbox-close aria-label="Minimizar central de suporte">
                <span aria-hidden="true">&times;</span>
            </button>
        </header>

        <div class="support-inbox__filters" role="group" aria-label="Filtrar conversas">
            <button type="button" class="support-inbox__filter is-active" data-support-inbox-filter="open" aria-pressed="true">
                Abertas
            </button>
            <button type="button" class="support-inbox__filter" data-support-inbox-filter="closed" aria-pressed="false">
                Encerradas
            </button>
        </div>

        <div class="support-inbox__layout">
            <div class="support-inbox__list" data-support-inbox-list role="tablist" aria-label="Conversas">
                <p class="support-inbox__empty" data-support-inbox-empty>Nenhuma conversa por aqui.</p>
            </div>

            <div class="support-inbox__thread" data-support-inbox-thread hidden>
                <header class="support-inbox__thread-head">
                    <div>
                        <h3 class="support-inbox__visitor" data-support-visitor-name></h3>
                        <p class="support-inbox__meta" data-support-visitor-meta></p>
                    </div>
                    <div class="support-inbox__thread-actions">
                        <span class="support-inbox__pill" data-support-conversation-status></span>
                        <button type="button" class="btn btn-ghost btn-sm" data-support-close-conversation hidden>
                            Encerrar
                        </button>
                        <button type="button" class="support-inbox__back" data-support-inbox-back aria-label="Voltar para a lista">
                            Voltar
                        </button>
                    </div>
                </header>

                <ol class="support-inbox__messages" data-support-inbox-messages aria-live="polite"></ol>

                <p class="support-inbox__closed-note" data-support-closed-note hidden>
                    Conversa encerrada. O histórico continua disponível, mas novas mensagens não são aceitas.
                </p>

                <form class="support-inbox__form" data-support-inbox-form>
                    <label class="visually-hidden" for="support-inbox-input">Mensagem</label>
                    <textarea
                        class="form-control form-control-sm"
                        id="support-inbox-input"
                        name="body"
                        rows="2"
                        maxlength="{{ (int) config('support.message_max_length', 2000) }}"
                        placeholder="Responder ao visitante…"
                        data-support-inbox-input
                        required
                    ></textarea>
                    <p class="support-inbox__error" data-support-inbox-error role="alert" hidden></p>
                    <button type="submit" class="btn btn-primary btn-sm" data-support-inbox-send>
                        Enviar
                    </button>
                </form>
            </div>
        </div>

        <p class="support-inbox__error" data-support-inbox-list-error role="alert" hidden></p>
    </section>
</div>
