@php
    $supportStatus = app(\App\Support\SupportChat\SupportPresence::class)->status();
    $supportMode = \App\Support\Realtime\RealtimeMode::current();
    $supportPoll = config('support.polling');
    // Em páginas públicas o visitante pode estar autenticado. Nesse caso os
    // campos já vêm com os dados da conta; anônimos continuam vendo-os vazios.
    $supportVisitor = auth()->user();
@endphp

{{--
    Widget de suporte do visitante.

    Aparece no canto inferior direito de todas as páginas públicas. A conversa
    sobrevive ao recarregamento porque o servidor reconhece o cookie HttpOnly;
    nenhum identificador de conversa ou token é publicado no HTML.
--}}
<div
    class="support-widget"
    data-support-widget
    data-show-url="{{ route('support.conversation.show') }}"
    data-start-url="{{ route('support.conversation.store') }}"
    data-message-url="{{ route('support.messages.store') }}"
    data-read-url="{{ route('support.messages.read') }}"
    data-broadcast-auth-url="{{ route('support.broadcast.auth') }}"
    data-realtime-mode="{{ $supportMode }}"
    data-poll-interval-ms="{{ (int) $supportPoll['visitor_messages_ms'] }}"
    data-poll-backup-interval-ms="{{ (int) $supportPoll['visitor_socket_backup_ms'] }}"
>
    <button
        type="button"
        class="support-widget__launcher"
        data-support-launcher
        aria-controls="support-widget-panel"
        aria-expanded="false"
        aria-label="Abrir suporte"
    >
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.8 8.8 0 0 1-3.4-.7L4 21l1.7-4.1A8.6 8.6 0 0 1 3.5 11 8.5 8.5 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5Z" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="support-widget__badge" data-support-badge hidden></span>
    </button>

    <section
        id="support-widget-panel"
        class="support-widget__panel"
        data-support-panel
        aria-label="Suporte Arcana"
        hidden
    >
        <header class="support-widget__header">
            <div>
                <h2 class="support-widget__title">Suporte Arcana</h2>
                <p class="support-widget__status" data-support-status>
                    <span class="support-widget__status-dot" data-support-status-dot></span>
                    <span data-support-status-label>{{ $supportStatus['label'] }}</span>
                </p>
            </div>
            <button type="button" class="support-widget__close" data-support-close aria-label="Minimizar suporte">
                <span aria-hidden="true">&times;</span>
            </button>
        </header>

        <div class="support-widget__body">
            <p class="support-widget__loading" data-support-loading>Carregando atendimento…</p>

            <form class="support-widget__start" data-support-start-form hidden novalidate>
                <p class="support-widget__intro">
                    Conte o que você precisa. A conversa fica registrada para a equipe responder
                    mesmo que ninguém esteja online agora.
                </p>

                <label class="form-label" for="support-visitor-name">Seu nome</label>
                <input
                    class="form-control form-control-sm"
                    id="support-visitor-name"
                    name="visitor_name"
                    type="text"
                    maxlength="80"
                    autocomplete="name"
                    value="{{ $supportVisitor?->name }}"
                    required
                >

                <label class="form-label mt-3" for="support-visitor-email">E-mail (opcional)</label>
                <input
                    class="form-control form-control-sm"
                    id="support-visitor-email"
                    name="visitor_email"
                    type="email"
                    maxlength="255"
                    autocomplete="email"
                    value="{{ $supportVisitor?->email }}"
                >

                <label class="form-label mt-3" for="support-visitor-message">Mensagem</label>
                <textarea
                    class="form-control form-control-sm"
                    id="support-visitor-message"
                    name="body"
                    rows="4"
                    maxlength="{{ (int) config('support.message_max_length', 2000) }}"
                    required
                ></textarea>

                <p class="support-widget__error" data-support-start-error role="alert" hidden></p>

                <button type="submit" class="btn btn-primary btn-sm w-100 mt-3" data-support-start-submit>
                    Iniciar conversa
                </button>
            </form>

            <div class="support-widget__conversation" data-support-conversation hidden>
                <ol class="support-widget__messages" data-support-messages aria-live="polite"></ol>

                <p class="support-widget__closed" data-support-closed hidden>
                    Atendimento encerrado. Se precisar de algo, inicie uma nova conversa.
                </p>

                <button type="button" class="btn btn-ghost btn-sm w-100" data-support-restart hidden>
                    Iniciar nova conversa
                </button>

                <form class="support-widget__form" data-support-form>
                    <label class="visually-hidden" for="support-message-input">Mensagem</label>
                    <textarea
                        class="form-control form-control-sm"
                        id="support-message-input"
                        name="body"
                        rows="2"
                        maxlength="{{ (int) config('support.message_max_length', 2000) }}"
                        placeholder="Escreva sua mensagem…"
                        data-support-input
                        required
                    ></textarea>
                    <p class="support-widget__error" data-support-send-error role="alert" hidden></p>
                    <button type="submit" class="btn btn-primary btn-sm" data-support-send>
                        Enviar
                    </button>
                </form>
            </div>
        </div>
    </section>
</div>
