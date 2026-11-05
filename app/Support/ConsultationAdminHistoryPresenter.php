<?php

namespace App\Support;

use App\Enums\MessageType;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\ConsultationPrivacyIncident;
use App\Support\Privacy\SensitiveDataDetector;
use Illuminate\Support\Collection;

/**
 * Linha do tempo do painel administrativo.
 *
 * Mistura mensagens e ocorrências em ordem cronológica. Mensagens antigas
 * gravadas antes do bloqueio são analisadas e exibidas com os dados mascarados,
 * para que o administrador consiga auditar sem reexpor o contato completo.
 */
final class ConsultationAdminHistoryPresenter
{
    /**
     * @param  Collection<int, ConsultationMessage>  $messages
     * @param  Collection<int, ConsultationPrivacyIncident>  $incidents
     * @return list<array<string, mixed>>
     */
    public static function toArray(
        Consultation $consultation,
        Collection $messages,
        Collection $incidents,
        SensitiveDataDetector $detector,
        bool $newestFirst = false,
    ): array {
        $entries = [];

        foreach ($messages as $message) {
            $entries[] = self::messageEntry($consultation, $message, $detector);
        }

        foreach ($incidents as $incident) {
            $entries[] = self::incidentEntry($consultation, $incident);
        }

        usort($entries, function (array $left, array $right): int {
            if ($left['timestamp_ms'] === $right['timestamp_ms']) {
                return strcmp((string) $left['key'], (string) $right['key']);
            }

            return $left['timestamp_ms'] <=> $right['timestamp_ms'];
        });

        return $newestFirst ? array_reverse($entries) : $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function messageEntry(
        Consultation $consultation,
        ConsultationMessage $message,
        SensitiveDataDetector $detector,
    ): array {
        $isCard = $message->type === MessageType::TarotCard;
        $detection = $isCard ? null : $detector->detect($message->body);

        return [
            'kind' => 'message',
            'key' => 'message-'.$message->getKey(),
            'id' => (int) $message->getKey(),
            'type' => $isCard ? 'card' : 'text',
            'timestamp' => $message->created_at?->toIso8601String(),
            'timestamp_ms' => $message->created_at?->getTimestampMs() ?? 0,
            'time_label' => $message->created_at?->format('d/m/Y H:i') ?? '—',
            'sender_name' => $message->sender_id === $consultation->attendant_id
                ? ($consultation->attendant?->attendantProfile?->display_name ?? $message->sender?->name ?? 'Participante')
                : ($message->sender?->name ?? 'Participante'),
            'sender_role_label' => $message->sender_id === $consultation->client_id ? 'Cliente' : 'Atendente',
            'body' => $detection !== null && $detection->hasMatches()
                ? $detection->maskedText
                : ($message->body ?? ''),
            'masked' => $detection !== null && $detection->hasMatches(),
            'sensitive_labels' => $detection?->labels() ?? [],
            'card_name' => $isCard ? $message->card_name : null,
            'deck_name' => $isCard ? $message->deck_name : null,
            'image_url' => $isCard && filled($message->image_path)
                ? route('admin.consultations.messages.image', [$consultation->getKey(), $message->getKey()])
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function incidentEntry(Consultation $consultation, ConsultationPrivacyIncident $incident): array
    {
        $user = $incident->user;
        $isAttendant = $user !== null && $consultation->isAttendant($user);

        return [
            'kind' => 'incident',
            'key' => 'incident-'.$incident->getKey(),
            'id' => (int) $incident->getKey(),
            'timestamp' => $incident->created_at?->toIso8601String(),
            'timestamp_ms' => $incident->created_at?->getTimestampMs() ?? 0,
            'time_label' => $incident->created_at?->format('d/m/Y H:i') ?? '—',
            'user_name' => $user?->name ?? 'Participante removido',
            'user_role_label' => $isAttendant ? 'Atendente' : 'Cliente',
            'category' => $incident->category?->value,
            'category_label' => $incident->categoryLabel(),
            'status' => $incident->status?->value,
            'status_label' => $incident->statusLabel(),
            'masked_excerpt' => $incident->masked_excerpt,
            'detected_labels' => $incident->detectedLabels(),
            'reviewed_at_label' => $incident->reviewed_at?->format('d/m/Y H:i'),
            'reviewed_by_name' => $incident->reviewer?->name,
            'review_notes' => $incident->review_notes,
            'review_url' => route('admin.consultations.incidents.review', [
                $consultation->getKey(),
                $incident->getKey(),
            ]),
        ];
    }
}
