<?php

namespace App\Models;

use App\Enums\SupportConversationStatus;
use App\Enums\SupportSenderType;
use Database\Factories\SupportConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'visitor_name', 'visitor_email', 'visitor_token_hash', 'status', 'assigned_user_id', 'last_message_at', 'closed_at'])]
class SupportConversation extends Model
{
    /** @use HasFactory<SupportConversationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (SupportConversation $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
            $conversation->status ??= SupportConversationStatus::Waiting;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SupportConversationStatus::class,
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }

    /**
     * @return HasOne<SupportMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'conversation_id')->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @param  Builder<SupportConversation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', SupportConversationStatus::openValues());
    }

    /**
     * @param  Builder<SupportConversation>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', SupportConversationStatus::Waiting->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function unreadCountForVisitor(): int
    {
        return $this->messages()
            ->where('sender_type', SupportSenderType::Operator->value)
            ->whereNull('read_at')
            ->count();
    }

    public function unreadCountForOperator(): int
    {
        return $this->messages()
            ->where('sender_type', SupportSenderType::Visitor->value)
            ->whereNull('read_at')
            ->count();
    }

    public function touchLastMessage(): void
    {
        $this->forceFill(['last_message_at' => now()])->save();
    }

    /**
     * Marca como lidas as mensagens que o visitante recebeu da equipe.
     */
    public function markMessagesReadForVisitor(): void
    {
        $this->messages()
            ->where('sender_type', SupportSenderType::Operator->value)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Marca como lidas as mensagens que a equipe recebeu do visitante.
     */
    public function markMessagesReadForOperator(): void
    {
        $this->messages()
            ->where('sender_type', SupportSenderType::Visitor->value)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
