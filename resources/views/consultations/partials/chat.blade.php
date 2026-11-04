@php
    $chatStatus = $consultation->status;
    $messageMaxLength = (int) config('consultation.message_max_length');
    $chatSendOnEnter = (bool) (auth()->user()?->chat_send_on_enter ?? true);
    $chatSoundEnabled = (bool) (auth()->user()?->chat_sound_enabled ?? true);

    /*
     * Modo de entrega decidido pelo servidor: `socket` quando existe um
     * broadcaster WebSocket (Reverb) e `poll` quando o ambiente não tem processo
     * persistente. Os intervalos vêm da configuração e são publicados aqui para
     * o bundle compilado não depender de variáveis `VITE_*`.
     */
    $chatRealtimeMode = \App\Support\Realtime\RealtimeMode::current();
    $chatPollIntervals = config('consultation.realtime.poll');
@endphp

<section
    id="chat"
    aria-labelledby="chat-title"
    data-chat
    data-consultation-id="{{ $consultation->id }}"
    data-current-user-id="{{ auth()->id() }}"
    data-realtime-mode="{{ $chatRealtimeMode }}"
    data-poll-interval-ms="{{ (int) $chatPollIntervals['chat_interval_ms'] }}"
    data-poll-hidden-interval-ms="{{ (int) $chatPollIntervals['chat_hidden_interval_ms'] }}"
    data-poll-max-backoff-ms="{{ (int) $chatPollIntervals['max_backoff_ms'] }}"
    data-feed-url="{{ route('consultations.messages.index', $consultation) }}"
    data-read-url="{{ route('consultations.messages.read', $consultation) }}"
    data-page-url="{{ request()->fullUrl() }}"
    data-workspace-url="{{ route('consultations.workspace', $consultation) }}"
    data-show-url="{{ route('consultations.show', $consultation) }}"
    data-status="{{ $chatStatus->value }}"
    data-first-page="{{ $messages->onFirstPage() ? '1' : '0' }}"
    data-workspace="{{ request()->routeIs('consultations.workspace') ? '1' : '0' }}"
    data-send-on-enter="{{ $chatSendOnEnter ? '1' : '0' }}"
    data-sound-enabled="{{ $chatSoundEnabled ? '1' : '0' }}"
    data-preferences-url="{{ route('preferences.chat.update') }}"
>
    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0" id="chat-title">Conversa</h2>
            <div class="d-flex flex-wrap align-items-center gap-2">
                @if (($isClient ?? false) && filled($creditsUrl ?? null))
                    {{--
                        Atalho de compra dentro da conversa. `data-allow-leave`
                        dispensa o aviso de saída porque a carteira guarda este
                        atendimento e oferece o retorno após a compra.
                    --}}
                    <a
                        class="btn btn-primary btn-sm"
                        href="{{ $creditsUrl }}"
                        data-allow-leave
                        data-consultation-credits-link
                    >
                        Comprar créditos
                    </a>
                @endif
                {{--
                    Preferências pessoais de conversa. O estado inicial vem do
                    banco (perfil do usuário) e o JS regrava via PATCH sem
                    recarregar a página, mantendo os `data-*` do bloco em sincronia.
                --}}
                <div class="chat-preferences" role="group" aria-label="Preferências da conversa">
                    <label class="chat-preferences__item">
                        <input
                            class="form-check-input mt-0"
                            type="checkbox"
                            role="switch"
                            id="chat-send-on-enter"
                            data-chat-preference="send_on_enter"
                            @checked($chatSendOnEnter)
                        >
                        <span>Enter envia</span>
                    </label>
                    <label class="chat-preferences__item">
                        <input
                            class="form-check-input mt-0"
                            type="checkbox"
                            role="switch"
                            id="chat-sound-enabled"
                            data-chat-preference="sound_enabled"
                            @checked($chatSoundEnabled)
                        >
                        <span>Som</span>
                    </label>
                </div>
                <span class="badge bg-secondary d-none" data-chat-connection role="status" aria-live="polite">
                    Conectando…
                </span>
                @if ($unreadMessagesCount > 0)
                    <span class="badge bg-warning text-dark" data-chat-unread>
                        {{ $unreadMessagesCount }} {{ $unreadMessagesCount === 1 ? 'nova' : 'novas' }}
                    </span>
                @endif
                @if ($chatStatus === \App\Enums\ConsultationStatus::Active)
                    <x-ui.status-badge status="active" label="Chat liberado" />
                @elseif ($chatStatus->isFinal())
                    <x-ui.status-badge :status="$chatStatus->value" :label="'Somente leitura'" />
                @endif
            </div>
        </x-ui.card-header>
        <x-ui.card-body>
            @if ($unreadMessagesCount > 0)
                <x-ui.alert type="info" :dismissible="false" data-chat-unread>
                    {{ $unreadMessagesCount }} {{ $unreadMessagesCount === 1 ? 'mensagem nova foi recebida' : 'mensagens novas foram recebidas' }} desde a sua última visita.
                </x-ui.alert>
            @endif

            <div class="alert alert-info py-2 px-3 mb-3 d-none" data-chat-notice role="status" aria-live="polite"></div>

            <div data-chat-empty @class(['d-none' => $messages->isNotEmpty()])>
                <x-ui.empty-state
                    icon="✧"
                    title="Nenhuma mensagem ainda"
                    text="A conversa desta consulta aparecerá aqui em ordem cronológica."
                />
            </div>

            @if ($messages->hasPages())
                <div class="mb-3">{{ $messages->links() }}</div>
            @endif

            <ol
                class="chat-timeline @if ($messages->isEmpty()) d-none @endif"
                data-chat-timeline
                aria-label="Histórico da conversa"
            >
                @foreach ($messages as $message)
                    @php
                        $mine = $message->sender_id === auth()->id();
                        $isCard = $message->type === \App\Enums\MessageType::TarotCard;
                        $senderName = $message->sender_id === $consultation->attendant_id
                            ? ($consultation->attendant->attendantProfile?->display_name ?? $message->sender->name)
                            : $message->sender->name;
                        $senderRole = $message->sender_id === $consultation->client_id ? 'Cliente' : 'Atendente';
                    @endphp

                    <li class="chat-message{{ $mine ? ' chat-message--mine' : '' }}" data-message-id="{{ $message->id }}">
                        <div class="chat-message__meta">
                            <span class="chat-message__sender">
                                {{ $mine ? 'Você' : $senderName }} · {{ $senderRole }}
                            </span>
                            <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                {{ $message->created_at?->format('d/m/Y H:i') ?? '—' }}
                            </time>
                        </div>

                        @if ($isCard)
                            <figure class="chat-card mb-0">
                                @if (filled($message->image_path))
                                    <a href="{{ route('consultations.messages.image', [$consultation, $message]) }}"
                                       target="_blank" rel="noopener">
                                        <img src="{{ route('consultations.messages.image', [$consultation, $message]) }}"
                                             alt="Carta {{ $message->card_name }}"
                                             loading="lazy">
                                    </a>
                                @endif
                                <figcaption>
                                    <strong>{{ $message->card_name }}</strong>
                                    <span>{{ $message->deck_name }}</span>
                                </figcaption>
                            </figure>
                        @else
                            <p class="chat-message__body">{{ $message->body }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($messages->hasPages())
                <div class="mt-3">{{ $messages->links() }}</div>
            @endif

            @if ($chatStatus === \App\Enums\ConsultationStatus::Active && Gate::allows('sendMessage', $consultation))
                <form
                    method="POST"
                    action="{{ route('consultations.messages.store', $consultation) }}"
                    class="chat-form mt-4"
                    data-chat-form="text"
                >
                    @csrf
                    <label class="form-label" for="chat-body">Nova mensagem</label>
                    <textarea
                        class="form-control @error('body') is-invalid @enderror"
                        id="chat-body"
                        name="body"
                        rows="3"
                        maxlength="{{ $messageMaxLength }}"
                        required
                        placeholder="Escreva sua mensagem para {{ $isClient ? 'o atendente' : 'o cliente' }}."
                    >{{ old('body') }}</textarea>
                    @error('body')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="invalid-feedback d-block d-none" data-chat-error role="alert"></div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                        <span class="form-text mt-0" data-chat-send-hint>
                            <span data-chat-send-mode>
                                @if ($chatSendOnEnter)
                                    Enter envia · Shift+Enter quebra linha.
                                @else
                                    Enter quebra linha · Ctrl+Enter envia.
                                @endif
                            </span>
                            Máximo de {{ $messageMaxLength }} caracteres.
                        </span>
                        <x-ui.button type="submit" variant="primary" size="sm">Enviar mensagem</x-ui.button>
                    </div>
                </form>
            @elseif ($chatStatus->isFinal())
                <p class="form-text mt-4 mb-0">
                    A consulta foi encerrada. A conversa permanece disponível somente para leitura.
                </p>
            @elseif ($chatStatus === \App\Enums\ConsultationStatus::Requested)
                <p class="form-text mt-4 mb-0">
                    O chat será liberado quando o atendente aceitar a consulta.
                </p>
            @endif

            @if ($isAttendant && $chatStatus === \App\Enums\ConsultationStatus::Active)
                <div class="chat-card-sender mt-4">
                    @if ($cardDecks->isEmpty())
                        <p class="form-text mb-0">
                            Nenhuma carta ativa com imagem está disponível para envio neste momento.
                        </p>
                    @else
                        <button
                            class="btn btn-ghost btn-sm"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#chat-card-picker"
                            aria-expanded="false"
                            aria-controls="chat-card-picker"
                        >
                            Enviar uma carta
                        </button>

                        <div class="collapse mt-3" id="chat-card-picker">
                            <form
                                method="POST"
                                action="{{ route('consultations.cards.store', $consultation) }}"
                                data-chat-form="card"
                            >
                                @csrf
                                <fieldset class="chat-card-picker">
                                    <legend class="form-label">Carta do Tarot</legend>

                                    @error('tarot_card_id')
                                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                                    @enderror

                                    @foreach ($cardDecks as $deck)
                                        <div class="chat-card-picker__deck">
                                            <p class="chat-card-picker__deck-name">
                                                {{ $deck->name }}
                                                <span class="chat-card-picker__deck-count">
                                                    {{ $deck->cards->count() }}
                                                    {{ $deck->cards->count() === 1 ? 'carta' : 'cartas' }}
                                                </span>
                                            </p>

                                            <div class="chat-card-picker__grid">
                                                @foreach ($deck->cards as $card)
                                                    <div class="chat-card-picker__option">
                                                        <input
                                                            class="chat-card-picker__input"
                                                            type="radio"
                                                            id="tarot-card-{{ $card->id }}"
                                                            name="tarot_card_id"
                                                            value="{{ $card->id }}"
                                                            required
                                                            @checked((string) old('tarot_card_id') === (string) $card->id)
                                                        >
                                                        <label class="chat-card-picker__label" for="tarot-card-{{ $card->id }}">
                                                            <img
                                                                class="chat-card-picker__image"
                                                                src="{{ route('tarot.cards.image', $card) }}"
                                                                alt="Carta {{ $card->name }}"
                                                                loading="lazy"
                                                            >
                                                            <span class="chat-card-picker__name">{{ $card->name }}</span>
                                                        </label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </fieldset>
                                <div class="invalid-feedback d-block d-none" data-chat-error role="alert"></div>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                                    <span class="form-text mt-0">
                                        A imagem será preservada no histórico mesmo se a carta for alterada depois.
                                    </span>
                                    <x-ui.button type="submit" variant="secondary" size="sm">Confirmar envio</x-ui.button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.card-body>
    </x-ui.card>
</section>

