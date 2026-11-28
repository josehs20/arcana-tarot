<?php

namespace App\Support\SupportChat;

use App\Enums\SupportConversationStatus;
use App\Enums\SupportSenderType;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Consultas da caixa de entrada administrativa.
 *
 * A fila aberta é ordenada pela última mensagem; o histórico encerrado tem
 * janela e limite para não crescer indefinidamente na interface.
 */
class SupportInbox
{
    public function __construct(private readonly SupportPresence $presence) {}

    /**
     * @return Collection<int, SupportConversation>
     */
    public function openConversations(): Collection
    {
        return $this->withUnreadCount(SupportConversation::query()->open())
            ->with(['latestMessage', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, SupportConversation>
     */
    public function closedConversations(): Collection
    {
        return $this->withUnreadCount(
            SupportConversation::query()
                ->where('status', SupportConversationStatus::Closed->value)
                ->where('last_message_at', '>=', now()->subDays((int) config('support.closed_history_days', 30))),
        )
            ->with(['latestMessage', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit((int) config('support.closed_history_limit', 50))
            ->get();
    }

    public function unreadTotal(): int
    {
        return SupportMessage::query()
            ->where('sender_type', SupportSenderType::Visitor->value)
            ->whereNull('read_at')
            ->whereHas('conversation', fn (Builder $query) => $query->open())
            ->count();
    }

    public function waitingCount(): int
    {
        return SupportConversation::query()->waiting()->count();
    }

    /**
     * @return array{open: int, waiting: int, unread_total: int, online: bool, online_label: string}
     */
    public function summary(): array
    {
        $status = $this->presence->status();

        return [
            'open' => SupportConversation::query()->open()->count(),
            'waiting' => $this->waitingCount(),
            'unread_total' => $this->unreadTotal(),
            'online' => $status['online'],
            'online_label' => $status['label'],
        ];
    }

    /**
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    private function withUnreadCount(Builder $query): Builder
    {
        return $query->withCount([
            'messages as unread_count' => fn (Builder $messages) => $messages
                ->where('sender_type', SupportSenderType::Visitor->value)
                ->whereNull('read_at'),
        ]);
    }
}
