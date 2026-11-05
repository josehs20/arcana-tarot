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

            {{--
                A linha do tempo tem rolagem própria e altura limitada: o campo de
                digitação e o seletor de cartas continuam visíveis enquanto o
                histórico cresce. `data-chat-stream` é lido pelo chat.js, que só
                acompanha o fim da conversa quando o participante já está lá.
            --}}
            <div class="chat-stream" data-chat-stream>
                <ol
                    class="chat-timeline @if ($messages->isEmpty()) d-none @endif"
                    data-chat-timeline
                    aria-label="Histórico da conversa"
                >
                @php
                    /*
                     * Agrupamento do histórico por lote de envio.
                     *
                     * As cartas de um mesmo envio nascem como mensagens
                     * separadas (auditoria e horário próprios), mas ler uma
                     * tiragem de três cartas como três mensagens espaça o
                     * conteúdo e enche a conversa de rolagem. O `batch_id` vem
                     * do servidor; mensagens de texto não têm lote e seguem
                     * uma por bloco.
                     */
                    $timelineGroups = [];

                    foreach ($messages as $timelineMessage) {
                        $lastGroup = count($timelineGroups) - 1;

                        if (
                            $timelineMessage->batch_id !== null
                            && $lastGroup >= 0
                            && $timelineGroups[$lastGroup]['batch_id'] === $timelineMessage->batch_id
                        ) {
                            $timelineGroups[$lastGroup]['messages'][] = $timelineMessage;

                            continue;
                        }

                        $timelineGroups[] = [
                            'batch_id' => $timelineMessage->batch_id,
                            'messages' => [$timelineMessage],
                        ];
                    }
                @endphp

                @foreach ($timelineGroups as $group)
                    @php
                        $message = $group['messages'][0];
                        $groupSize = count($group['messages']);
                        $mine = $message->sender_id === auth()->id();
                        $isCard = $message->type === \App\Enums\MessageType::TarotCard;
                        $senderName = $message->sender_id === $consultation->attendant_id
                            ? ($consultation->attendant->attendantProfile?->display_name ?? $message->sender->name)
                            : $message->sender->name;
                        $senderRole = $message->sender_id === $consultation->client_id ? 'Cliente' : 'Atendente';
                    @endphp

                    <li
                        class="chat-message{{ $mine ? ' chat-message--mine' : '' }}"
                        @if ($group['batch_id'] !== null) data-message-batch="{{ $group['batch_id'] }}" @endif
                        @if (! $isCard) data-message-id="{{ $message->id }}" @endif
                    >
                        <div class="chat-message__meta">
                            <span class="chat-message__sender">
                                {{ $mine ? 'Você' : $senderName }} · {{ $senderRole }}
                            </span>
                            @if ($isCard && $groupSize > 1)
                                <span class="chat-message__badge" data-chat-cards-count>
                                    {{ $groupSize }} cartas
                                </span>
                            @endif
                            <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                {{ $message->created_at?->format('d/m/Y H:i') ?? '—' }}
                            </time>
                        </div>

                        @if ($isCard)
                            {{-- Um envio, um bloco: as cartas ficam lado a lado. --}}
                            <div class="chat-cards" data-chat-cards>
                                @foreach ($group['messages'] as $cardMessage)
                                    <figure
                                        class="chat-card mb-0"
                                        data-message-id="{{ $cardMessage->id }}"
                                    >
                                        @if (filled($cardMessage->image_path))
                                            <a href="{{ route('consultations.messages.image', [$consultation, $cardMessage]) }}"
                                               target="_blank" rel="noopener">
                                                <img src="{{ route('consultations.messages.image', [$consultation, $cardMessage]) }}"
                                                     alt="Carta {{ $cardMessage->card_name }}"
                                                     loading="lazy">
                                            </a>
                                        @endif
                                        <figcaption>
                                            <strong>{{ $cardMessage->card_name }}</strong>
                                            <span>{{ $cardMessage->deck_name }}</span>
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        @else
                            <p class="chat-message__body">{{ $message->body }}</p>
                        @endif
                    </li>
                @endforeach
                </ol>

                {{-- Aparece quando chega mensagem nova com a rolagem fora do fim. --}}
                <button type="button" class="chat-jump d-none" data-chat-jump>
                    <span data-chat-jump-label>Novas mensagens</span>
                    <span class="chat-jump__icon" aria-hidden="true">↓</span>
                </button>
            </div>

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
                    <p class="form-text mb-0" data-chat-privacy-hint>
                        Não envie CPF, CNPJ, e-mail, telefone ou links externos: o servidor bloqueia a
                        mensagem e registra a tentativa para auditoria.
                    </p>
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
                            Enviar cartas
                        </button>

                        <div class="collapse mt-3" id="chat-card-picker">
                            <form
                                method="POST"
                                action="{{ route('consultations.cards.store', $consultation) }}"
                                data-chat-form="card"
                                data-chat-card-form
                                data-max-cards="{{ \App\Support\ConsultationMessageManager::MAX_CARDS_PER_SEND }}"
                            >
                                @csrf
                                <fieldset class="chat-card-picker" data-chat-card-picker>
                                    <legend class="form-label">Cartas do Tarot</legend>

                                    @error('tarot_card_ids')
                                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                                    @enderror
                                    @error('tarot_card_ids.*')
                                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                                    @enderror
                                    @error('tarot_card_id')
                                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                                    @enderror
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                                    <span class="form-text mt-0">
                                        A imagem será preservada no histórico mesmo se a carta for alterada depois.
                                    </span>
                                    <x-ui.button type="submit" variant="secondary" size="sm">Enviar selecionadas</x-ui.button>
                                </div>
                                    {{-- Baralho primeiro, cartas depois: a grade é filtrada no navegador. --}}
                                    <div class="chat-card-picker__toolbar">
                                        <label class="chat-card-picker__deck-select">
                                            <span class="chat-card-picker__deck-select-label">Baralho</span>
                                            <select class="form-select form-select-sm" data-chat-deck-filter>
                                                <option value="">Todos os baralhos</option>
                                                @foreach ($cardDecks as $deck)
                                                    <option value="{{ $deck->id }}">
                                                        {{ $deck->name }} ({{ $deck->cards->count() }})
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <p class="chat-card-picker__counter" data-chat-card-counter aria-live="polite">
                                            0 de {{ \App\Support\ConsultationMessageManager::MAX_CARDS_PER_SEND }} cartas selecionadas
                                        </p>
                                    </div>

                                    @foreach ($cardDecks as $deck)
                                        <div class="chat-card-picker__deck" data-chat-deck="{{ $deck->id }}">
                                            <p class="chat-card-picker__deck-name">
                                                {{ $deck->name }}
                                                <span class="chat-card-picker__deck-count">
                                                    {{ $deck->cards->count() }}
                                                    {{ $deck->cards->count() === 1 ? 'carta' : 'cartas' }}
                                                </span>
                                            </p>

                                            <div class="chat-card-picker__grid">
                                                @foreach ($deck->cards as $card)
                                                    @php
                                                        $cardImageUrl = route('tarot.cards.image', $card);
                                                        $cardChecked = in_array((string) $card->id, array_map('strval', (array) old('tarot_card_ids', [])), true)
                                                            || (string) old('tarot_card_id') === (string) $card->id;
                                                    @endphp

                                                    <div
                                                        class="chat-card-picker__option"
                                                        data-chat-card-option
                                                        data-card-name="{{ $card->name }}"
                                                        data-card-deck="{{ $deck->name }}"
                                                        data-card-image="{{ $cardImageUrl }}"
                                                    >
                                                        <input
                                                            class="chat-card-picker__input"
                                                            type="checkbox"
                                                            id="tarot-card-{{ $card->id }}"
                                                            name="tarot_card_ids[]"
                                                            value="{{ $card->id }}"
                                                            data-chat-card-input
                                                            @checked($cardChecked)
                                                        >
                                                        <label class="chat-card-picker__label" for="tarot-card-{{ $card->id }}">
                                                            <img
                                                                class="chat-card-picker__image"
                                                                src="{{ $cardImageUrl }}"
                                                                alt="Carta {{ $card->name }}"
                                                                loading="lazy"
                                                            >
                                                            <span class="chat-card-picker__name">{{ $card->name }}</span>
                                                            <span class="chat-card-picker__check" aria-hidden="true">✓</span>
                                                        </label>
                                                        <button
                                                            type="button"
                                                            class="chat-card-picker__zoom"
                                                            data-chat-card-preview
                                                            aria-label="Ampliar a carta {{ $card->name }}"
                                                        >
                                                            <x-ui.icon name="external-link" width="13" height="13" />
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </fieldset>

                                <div class="chat-card-picker__selection d-none" data-chat-selection>
                                    <p class="chat-card-picker__selection-title">Selecionadas</p>
                                    <ul class="chat-card-picker__chips" data-chat-selection-list></ul>
                                </div>
                                <div class="invalid-feedback d-block d-none" data-chat-error role="alert"></div>

                            </form>
                        </div>

                        {{-- Pré-visualização ampliada antes de selecionar a carta. --}}
                        <div
                            class="modal fade chat-card-preview"
                            id="chat-card-preview"
                            tabindex="-1"
                            aria-labelledby="chat-card-preview-title"
                            aria-hidden="true"
                            data-chat-card-preview-modal
                        >
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h2 class="modal-title h5" id="chat-card-preview-title" data-chat-card-preview-title>
                                            Carta
                                        </h2>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body chat-card-preview__body">
                                        <img class="chat-card-preview__image" src="" alt="" data-chat-card-preview-image>
                                        <p class="form-text mb-0" data-chat-card-preview-deck></p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Fechar</button>
                                        <button type="button" class="btn btn-primary" data-chat-card-preview-select>
                                            Selecionar carta
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.card-body>
    </x-ui.card>
</section>

