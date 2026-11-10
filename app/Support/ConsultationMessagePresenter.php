<?php

namespace App\Support;

use App\Enums\MessageType;
use App\Models\ConsultationMessage;

/**
 * Representação pública de uma mensagem para eventos Reverb e histórico HTTP.
 *
 * Nunca expõe o caminho físico do snapshot da carta. A imagem é referenciada
 * pela rota autorizada, que revalida a participação na consulta.
 */
final class ConsultationMessagePresenter
{
    /**
     * @return array{
     *     id: int,
     *     consultation_id: int,
     *     sender_id: int,
     *     sender_name: string,
     *     sender_role: string,
     *     sender_role_label: string,
     *     type: string,
     *     batch_id: string|null,
     *     body: string|null,
     *     tarot_card_id: int|null,
     *     card_name: string|null,
     *     deck_name: string|null,
     *     image_url: string|null,
     *     image_thumb_url: string|null,
     *     created_at: string|null,
     *     created_at_label: string
     * }
     */
    public static function toArray(ConsultationMessage $message): array
    {
        $message->loadMissing(['sender.attendantProfile', 'consultation.attendant.attendantProfile']);

        $consultation = $message->consultation;
        $sender = $message->sender;
        $isAttendant = $consultation !== null && $sender !== null && $consultation->isAttendant($sender);
        $isCard = $message->type === MessageType::TarotCard;

        $senderName = $isAttendant
            ? ($consultation->attendant->attendantProfile?->display_name ?? $sender->name)
            : ($sender->name ?? 'Participante');

        return [
            'id' => (int) $message->getKey(),
            'consultation_id' => (int) $message->consultation_id,
            'sender_id' => (int) $message->sender_id,
            'sender_name' => $senderName,
            'sender_role' => $isAttendant ? 'attendant' : 'client',
            'sender_role_label' => $isAttendant ? 'Atendente' : 'Cliente',
            'type' => $message->type->value,
            // Lote de cartas enviadas juntas: o chat usa o mesmo identificador
            // para desenhar as imagens de um envio em um único bloco.
            'batch_id' => $message->batch_id,
            'body' => $isCard ? null : $message->body,
            'tarot_card_id' => $message->tarot_card_id,
            'card_name' => $isCard ? $message->card_name : null,
            'deck_name' => $isCard ? $message->deck_name : null,
            'image_url' => $isCard && filled($message->image_path)
                ? route('consultations.messages.image', [$message->consultation_id, $message->getKey()])
                : null,
            // O balão do chat usa a miniatura (WebP reduzido, gerado uma vez no
            // servidor); a imagem cheia é aberta no clique do link.
            'image_thumb_url' => $isCard && filled($message->image_path)
                ? route('consultations.messages.thumb', [$message->consultation_id, $message->getKey()])
                : null,
            'created_at' => $message->created_at?->toIso8601String(),
            'created_at_label' => $message->created_at?->format('d/m/Y H:i') ?? '—',
        ];
    }
}
