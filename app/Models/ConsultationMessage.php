<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\ConsultationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultation_id', 'sender_id', 'type', 'body', 'tarot_card_id', 'deck_name', 'card_name', 'image_path'])]
class ConsultationMessage extends Model
{
    /** @use HasFactory<ConsultationMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function tarotCard(): BelongsTo
    {
        return $this->belongsTo(TarotCard::class, 'tarot_card_id');
    }
}
