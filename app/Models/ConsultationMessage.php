<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\ConsultationMessageFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultation_id', 'sender_id', 'type', 'batch_id', 'body', 'tarot_card_id', 'deck_name', 'card_name', 'image_path', 'audio_path', 'audio_duration_seconds'])]
class ConsultationMessage extends Model
{
    /** @use HasFactory<ConsultationMessageFactory> */
    use HasFactory;

    /**
     * Impede gravação fora de consulta ativa e falsificação de cartas/áudios.
     *
     * O cliente participa do chat e pode enviar texto — e voz, no atendimento
     * por áudio —, mas somente o atendente escalado pode registrar cartas do
     * tarô, e apenas nos atendimentos com conversa persistida (texto e áudio).
     * A autorização HTTP repete a regra; o model protege jobs, comandos e
     * importações.
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

            if ($message->type === MessageType::TarotCard) {
                if (! $isAttendant) {
                    throw new DomainException('Apenas o atendente pode enviar cartas do tarô.');
                }

                if (! ($consultation->modality?->allowsTarotCards() ?? true)) {
                    throw new DomainException('Cartas do tarô não são enviadas em chamadas de vídeo.');
                }
            }

            if ($message->type === MessageType::Voice) {
                if (! $consultation->allowsVoiceMessages()) {
                    throw new DomainException('Mensagens de voz são exclusivas do atendimento por áudio.');
                }

                if (! filled($message->audio_path)) {
                    throw new DomainException('A mensagem de voz precisa de um arquivo de áudio.');
                }
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
            'audio_duration_seconds' => 'integer',
        ];
    }

    /**
     * Duração da gravação no formato "m:ss" (ex.: "0:42").
     */
    public function audioDurationLabel(): ?string
    {
        $seconds = $this->audio_duration_seconds;

        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
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
