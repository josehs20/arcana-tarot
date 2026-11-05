{{--
    Um item da linha do tempo administrativa: mensagem (texto ou carta) ou
    ocorrência de dado pessoal.

    O partial é usado tanto na página quanto na atualização automática, para que
    o mascaramento e a formatação fiquem em um único lugar.

    Espera: $entry (array do ConsultationAdminHistoryPresenter).
--}}
@if ($entry['kind'] === 'incident')
    <li
        class="alert alert-warning mb-3"
        id="ocorrencia-{{ $entry['id'] }}"
        data-history-key="{{ $entry['key'] }}"
        data-history-incident="{{ $entry['id'] }}"
    >
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <strong>Tentativa bloqueada · {{ $entry['category_label'] }}</strong>
            <x-ui.status-badge
                :status="$entry['status'] === 'reviewed' ? 'approved' : 'pending'"
                :label="$entry['status_label']"
            />
        </div>

        <p class="small mb-2">
            {{ $entry['user_name'] }} · {{ $entry['user_role_label'] }} ·
            <time datetime="{{ $entry['timestamp'] }}">{{ $entry['time_label'] }}</time>
        </p>

        <p class="mb-2"><code>{{ $entry['masked_excerpt'] }}</code></p>

        @if ($entry['detected_labels'] !== [])
            <p class="form-text mb-2">
                Detectado e mascarado: {{ implode(', ', $entry['detected_labels']) }}.
            </p>
        @endif

        @if ($entry['reviewed_at_label'] !== null)
            <p class="form-text mb-0">
                Revisada em {{ $entry['reviewed_at_label'] }}
                @if ($entry['reviewed_by_name'] !== null)
                    por {{ $entry['reviewed_by_name'] }}
                @endif
                @if (filled($entry['review_notes']))
                    — {{ $entry['review_notes'] }}
                @endif
            </p>
        @else
            <form method="POST" action="{{ $entry['review_url'] }}" class="d-flex flex-wrap gap-2 align-items-center mt-2">
                @csrf
                @method('PATCH')

                <label class="visually-hidden" for="review-notes-{{ $entry['id'] }}">Observação da revisão</label>
                <input
                    class="form-control form-control-sm flex-grow-1"
                    type="text"
                    id="review-notes-{{ $entry['id'] }}"
                    name="review_notes"
                    maxlength="500"
                    placeholder="Observação da revisão (opcional)"
                >
                <x-ui.button type="submit" variant="ghost" size="sm">Marcar como revisada</x-ui.button>
            </form>
        @endif
    </li>
@else
    <li class="chat-message chat-message--admin" data-history-key="{{ $entry['key'] }}" data-history-message="{{ $entry['id'] }}">
        <div class="chat-message__meta">
            <span class="chat-message__sender">
                {{ $entry['sender_name'] }} · {{ $entry['sender_role_label'] }}
            </span>
            <time datetime="{{ $entry['timestamp'] }}">{{ $entry['time_label'] }}</time>
        </div>

        @if ($entry['type'] === 'card')
            <figure class="chat-card mb-0">
                @if (filled($entry['image_url']))
                    <a href="{{ $entry['image_url'] }}" target="_blank" rel="noopener">
                        <img src="{{ $entry['image_url'] }}" alt="Carta {{ $entry['card_name'] }}" loading="lazy">
                    </a>
                @endif
                <figcaption>
                    <strong>{{ $entry['card_name'] }}</strong>
                    <span>{{ $entry['deck_name'] }}</span>
                </figcaption>
            </figure>
        @else
            <p class="chat-message__body">{{ $entry['body'] }}</p>

            @if ($entry['masked'])
                <p class="form-text mb-0">
                    Exibição mascarada: {{ implode(', ', $entry['sensitive_labels']) }}.
                </p>
            @endif
        @endif
    </li>
@endif
