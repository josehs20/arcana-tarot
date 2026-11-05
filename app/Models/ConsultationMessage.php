<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\ConsultationMessageFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultation_id', 'sender_id', 'type', 'batch_id', 'body', 'tarot_card_id', 'deck_name', 'card_name', 'image_path'])]
class ConsultationMessage extends Model
{
    /** @use HasFactory<ConsultationMessageFactory> */
    use HasFactory;

    /**
     * Impede gravação fora de consulta ativa e falsificação de cartas.
     *
     * O cliente participa do chat e pode enviar texto, mas somente o
     * atendente escalado pode registrar cartas do tarô. A autorização HTTP
     * repete a regra; o model protege jobs, comandos e importações.
     */
    protected static function booted(): void
    {
        static::saving(function (ConsultationMessage $message): void {
            $consultation = Consultation::query()->find($message->consultation_id);

            if ($consultation === null) {
                throw new DomainException('A consulta informada não existe.');
            }

            if (! $consultation->allowsMessaging()) {
                throw new DomainException('Só é possível enviar mensagens em uma consulta ativa.');
            }

            $isAttendant = $message->sender_id === $consultation->attendant_id;

            if ($message->type === MessageType::TarotCard && ! $isAttendant) {
                throw new DomainException('Apenas o atendente pode enviar cartas do tarô.');
            }

            if ($message->sender_id !== $consultation->client_id && ! $isAttendant) {
                throw new DomainException('O remetente não participa desta consulta.');
            }
        });
    }

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
