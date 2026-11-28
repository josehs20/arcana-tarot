<?php

namespace App\Models;

use App\Enums\SupportSenderType;
use Database\Factories\SupportMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'sender_type', 'sender_id', 'body', 'read_at'])]
class SupportMessage extends Model
{
    /** @use HasFactory<SupportMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sender_type' => SupportSenderType::class,
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isFromVisitor(): bool
    {
        return $this->sender_type === SupportSenderType::Visitor;
    }

    public function senderName(): string
    {
        if ($this->isFromVisitor()) {
            return $this->conversation->visitor_name;
        }

        return $this->sender?->name ?? 'Equipe Arcana';
    }
}
