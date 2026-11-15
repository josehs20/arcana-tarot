<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Leitura do histórico de mensagens de uma consulta.
 *
 * A paginação consulta da mensagem mais recente para a mais antiga (para o
 * banco) e entrega cada página em ordem cronológica, como o chat exibe. O
 * contador de novas e a marcação de leitura usam sempre as colunas de leitura
 * do próprio participante autenticado.
 *
 * Autorização (política `viewMessages`/`sendCard`) continua no controller; este
 * serviço só entrega dados e não lê sessão.
 */
final class ConsultationMessageFeed
{
    /**
     * @return LengthAwarePaginator<int, ConsultationMessage>
     */
    public function paginate(Consultation $consultation): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, ConsultationMessage> $messages */
        $messages = $consultation->messages()
            ->with('sender')
            ->orderByDesc('id')
            ->paginate((int) config('consultation.message_page_size'));

        // Paginação do mais recente para o mais antigo; exibição cronológica.
        $messages->setCollection($messages->getCollection()->reverse()->values());

        return $messages;
    }

    public function unreadCount(Consultation $consultation, User $user): int
    {
        return $consultation->unreadMessageCountFor($user);
    }

    public function markRead(Consultation $consultation, User $user): void
    {
        $consultation->markMessagesReadBy($user);
    }

    /**
     * Baralhos com cartas ativas e arquivo de imagem presente. Somente o
     * atendente da consulta ativa recebe esta lista (gate no controller).
     *
     * @return Collection<int, TarotDeck>
     */
    public function availableCardDecks(): Collection
    {
        return TarotDeck::query()
            ->active()
            ->whereHas('cards', fn (Builder $query) => $query->where('is_active', true))
            ->with(['cards' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(function (TarotDeck $deck): TarotDeck {
                $deck->setRelation(
                    'cards',
                    $deck->cards
                        ->filter(fn (TarotCard $card): bool => $card->hasStoredImage())
                        ->values(),
                );

                return $deck;
            })
            ->filter(fn (TarotDeck $deck): bool => $deck->cards->isNotEmpty())
            ->values();
    }
}
