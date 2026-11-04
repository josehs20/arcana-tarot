<?php

namespace App\Support;

use App\Enums\MessageType;
use App\Events\ConsultationMessageSent;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envio persistente de mensagens do chat.
 *
 * O remetente vem sempre da sessão autenticada. Metadados de carta também são
 * resolvidos no servidor: o navegador envia apenas o ID, nunca nome, caminho de
 * imagem, horário ou autor.
 */
final class ConsultationMessageManager
{
    public function __construct(
        private readonly ConsultationCardImageStorage $images,
        private readonly ConsultationActivityTracker $activity,
    ) {}

    public function sendText(Consultation $consultation, User $sender, string $body): ConsultationMessage
    {
        $message = DB::transaction(function () use ($consultation, $sender, $body): ConsultationMessage {
            $locked = $this->lockMessagingConsultation($consultation, 'body');
            $this->ensureParticipant($locked, $sender);
            $this->activity->touchActivity($locked, $sender);

            return ConsultationMessage::query()->create([
                'consultation_id' => $locked->getKey(),
                'sender_id' => $sender->getKey(),
                'type' => MessageType::Text,
                'body' => $body,
            ]);
        });

        $this->announce($message);

        return $message;
    }

    public function sendCard(Consultation $consultation, User $sender, int $cardId): ConsultationMessage
    {
        $snapshotPath = null;

        try {
            $message = DB::transaction(function () use ($consultation, $sender, $cardId, &$snapshotPath): ConsultationMessage {
                $locked = $this->lockMessagingConsultation($consultation, 'tarot_card_id');
                $this->ensureParticipant($locked, $sender);

                if (! $locked->isAttendant($sender)) {
                    throw new AuthorizationException('Apenas o atendente da consulta pode enviar cartas.');
                }

                $this->activity->touchActivity($locked, $sender);

                $card = TarotCard::query()
                    ->with('deck')
                    ->lockForUpdate()
                    ->find($cardId);

                if ($card === null) {
                    throw ValidationException::withMessages([
                        'tarot_card_id' => 'A carta selecionada não está disponível.',
                    ]);
                }

                if (! $card->is_active || $card->deck === null || ! $card->deck->is_active) {
                    throw ValidationException::withMessages([
                        'tarot_card_id' => 'Escolha uma carta ativa de um baralho ativo.',
                    ]);
                }

                $snapshotPath = $this->images->snapshot($locked, $card);

                return ConsultationMessage::query()->create([
                    'consultation_id' => $locked->getKey(),
                    'sender_id' => $sender->getKey(),
                    'type' => MessageType::TarotCard,
                    'body' => null,
                    'tarot_card_id' => $card->getKey(),
                    'deck_name' => $card->deck->name,
                    'card_name' => $card->name,
                    'image_path' => $snapshotPath,
                ]);
            });
        } catch (Throwable $exception) {
            $this->images->discardNewFile($snapshotPath);

            throw $exception;
        }

        $this->announce($message);

        return $message;
    }

    /**
     * Anuncia somente depois do commit da mensagem. Uma falha de fila/broadcast
     * é registrada em log e não desfaz a persistência, que é a fonte de verdade.
     */
    private function announce(ConsultationMessage $message): void
    {
        try {
            ConsultationMessageSent::dispatch(
                (int) $message->consultation_id,
                ConsultationMessagePresenter::toArray($message),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function lockMessagingConsultation(Consultation $consultation, string $errorField): Consultation
    {
        $locked = Consultation::query()->lockForUpdate()->find($consultation->getKey());

        if ($locked === null || ! $locked->allowsMessaging()) {
            throw ValidationException::withMessages([
                $errorField => 'Esta consulta não está ativa e não aceita novas mensagens.',
            ]);
        }

        return $locked;
    }

    private function ensureParticipant(Consultation $consultation, User $sender): void
    {
        if (! $sender->is_active || ! $consultation->hasParticipant($sender)) {
            throw new AuthorizationException('Você não participa desta consulta.');
        }
    }
}
