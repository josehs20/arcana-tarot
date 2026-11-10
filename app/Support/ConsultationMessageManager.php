<?php

namespace App\Support;

use App\Enums\MessageType;
use App\Events\ConsultationMessageSent;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\Privacy\ConsultationPrivacyGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    /**
     * Teto de cartas por envio: protege a transação, o disco de snapshots e a
     * leitura da conversa no celular.
     */
    public const MAX_CARDS_PER_SEND = 10;

    public function __construct(
        private readonly ConsultationCardImageStorage $images,
        private readonly ConsultationVoiceStorage $voices,
        private readonly ConsultationActivityTracker $activity,
        private readonly ConsultationPrivacyGuard $privacy,
    ) {}

    /**
     * Grava a mensagem de voz. O arquivo já está validado e persistido pelo
     * {@see ConsultationVoiceStorage}; se a transação falhar, o áudio recém
     * criado é descartado para não deixar órfãos no disco privado.
     */
    public function sendVoice(
        Consultation $consultation,
        User $sender,
        string $audioPath,
        ?int $durationSeconds = null,
    ): ConsultationMessage {
        try {
            $message = DB::transaction(function () use ($consultation, $sender, $audioPath, $durationSeconds): ConsultationMessage {
                $locked = $this->lockMessagingConsultation($consultation, 'audio');

                if (! $locked->allowsVoiceMessages()) {
                    throw ValidationException::withMessages([
                        'audio' => 'Mensagens de voz são exclusivas do atendimento por áudio.',
                    ]);
                }

                $this->ensureParticipant($locked, $sender);
                $this->activity->touchActivity($locked, $sender);

                return ConsultationMessage::query()->create([
                    'consultation_id' => $locked->getKey(),
                    'sender_id' => $sender->getKey(),
                    'type' => MessageType::Voice,
                    'body' => null,
                    'audio_path' => $audioPath,
                    'audio_duration_seconds' => $durationSeconds,
                ]);
            });
        } catch (Throwable $exception) {
            $this->voices->discardNewFile($audioPath);

            throw $exception;
        }

        $this->announce($message);

        return $message;
    }

    public function sendText(Consultation $consultation, User $sender, string $body): ConsultationMessage
    {
        /*
         * O bloqueio de dados pessoais acontece antes da transação: a ocorrência
         * é gravada fora dela para que o rollback (disparado pelo 422) não
         * apague o registro da tentativa.
         */
        $this->privacy->enforce($consultation, $sender, $body);

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
        return $this->sendCards($consultation, $sender, [$cardId])->first();
    }

    /**
     * Envia várias cartas de uma vez, na ordem escolhida pelo atendente.
     *
     * Tudo acontece em uma única transação: ou todas as cartas são gravadas e
     * anunciadas, ou nenhuma é. As imagens capturadas são descartadas quando a
     * transação falha, para não deixar arquivos órfãos no disco.
     *
     * Todas as cartas do envio compartilham o mesmo `batch_id`: o chat desenha
     * o lote como um bloco único, com as imagens lado a lado, em vez de uma
     * mensagem por carta rolando a conversa.
     *
     * @param  list<int>  $cardIds
     * @return Collection<int, ConsultationMessage>
     */
    public function sendCards(Consultation $consultation, User $sender, array $cardIds): Collection
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));

        if ($cardIds === []) {
            throw ValidationException::withMessages([
                'tarot_card_ids' => 'Selecione ao menos uma carta para enviar.',
            ]);
        }

        if (count($cardIds) > self::MAX_CARDS_PER_SEND) {
            throw ValidationException::withMessages([
                'tarot_card_ids' => 'Envie no máximo '.self::MAX_CARDS_PER_SEND.' cartas por vez.',
            ]);
        }

        $batchId = (string) Str::uuid();

        /** @var list<string> $snapshotPaths */
        $snapshotPaths = [];

        try {
            $messages = DB::transaction(function () use ($consultation, $sender, $cardIds, $batchId, &$snapshotPaths): Collection {
                $locked = $this->lockMessagingConsultation($consultation, 'tarot_card_ids');
                $this->ensureParticipant($locked, $sender);

                if (! $locked->isAttendant($sender)) {
                    throw new AuthorizationException('Apenas o atendente da consulta pode enviar cartas.');
                }

                $this->activity->touchActivity($locked, $sender);

                $cards = TarotCard::query()
                    ->with('deck')
                    ->lockForUpdate()
                    ->whereIn('id', $cardIds)
                    ->get()
                    ->keyBy('id');

                $created = collect();

                foreach ($cardIds as $cardId) {
                    $card = $cards->get($cardId);

                    if ($card === null) {
                        throw ValidationException::withMessages([
                            'tarot_card_ids' => 'Uma das cartas selecionadas não está disponível.',
                        ]);
                    }

                    if (! $card->is_active || $card->deck === null || ! $card->deck->is_active) {
                        throw ValidationException::withMessages([
                            'tarot_card_ids' => 'Escolha apenas cartas ativas de baralhos ativos.',
                        ]);
                    }

                    $snapshotPath = $this->images->snapshot($locked, $card);
                    $snapshotPaths[] = $snapshotPath;

                    $created->push(ConsultationMessage::query()->create([
                        'consultation_id' => $locked->getKey(),
                        'sender_id' => $sender->getKey(),
                        'type' => MessageType::TarotCard,
                        'batch_id' => $batchId,
                        'body' => null,
                        'tarot_card_id' => $card->getKey(),
                        'deck_name' => $card->deck->name,
                        'card_name' => $card->name,
                        'image_path' => $snapshotPath,
                    ]));
                }

                return $created;
            });
        } catch (Throwable $exception) {
            foreach ($snapshotPaths as $snapshotPath) {
                $this->images->discardNewFile($snapshotPath);
            }

            throw $exception;
        }

        $messages->each(fn (ConsultationMessage $message) => $this->announce($message));

        return $messages;
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
