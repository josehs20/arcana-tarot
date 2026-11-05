<?php

namespace App\Support\Privacy;

use App\Enums\PrivacyIncidentStatus;
use App\Models\Consultation;
use App\Models\ConsultationPrivacyIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bloqueio de dados pessoais no chat.
 *
 * É a única porta de entrada do servidor: o manager de mensagens chama
 * `enforce()` antes de persistir qualquer texto, então nem a interface, nem o
 * formulário HTML, nem uma requisição direta à API conseguem gravar contatos
 * externos. A ocorrência é registrada fora da transação da mensagem para que o
 * rollback do envio não apague o histórico de tentativas.
 */
final class ConsultationPrivacyGuard
{
    public function __construct(private readonly SensitiveDataDetector $detector) {}

    /**
     * @throws AuthorizationException quando o remetente não participa da consulta
     * @throws ValidationException quando o texto contém dado pessoal ou contato externo
     */
    public function enforce(Consultation $consultation, User $sender, string $body, ?string $ipAddress = null): SensitiveDataDetection
    {
        $detection = $this->detector->detect($body);

        if (! $detection->hasMatches()) {
            return $detection;
        }

        if (! $sender->is_active || ! $consultation->hasParticipant($sender)) {
            throw new AuthorizationException('Você não participa desta consulta.');
        }

        $this->record($consultation, $sender, $body, $detection, $ipAddress ?? $this->currentIpAddress());

        throw ValidationException::withMessages([
            'body' => $this->blockedMessage($detection),
        ]);
    }

    public function blockedMessage(SensitiveDataDetection $detection): string
    {
        return 'Mensagem bloqueada por conter dado pessoal ou contato externo ('
            .$detection->summary()
            .'). Para sua segurança, combine os detalhes pelo próprio chat.';
    }

    private function record(
        Consultation $consultation,
        User $sender,
        string $body,
        SensitiveDataDetection $detection,
        ?string $ipAddress,
    ): void {
        try {
            ConsultationPrivacyIncident::query()->create([
                'consultation_id' => $consultation->getKey(),
                'user_id' => $sender->getKey(),
                'category' => $detection->primaryCategory(),
                'status' => PrivacyIncidentStatus::Blocked,
                'detected_types' => $detection->toArray(),
                'masked_excerpt' => $detection->maskedExcerpt,
                'body_original' => $body,
                'ip_address' => $ipAddress,
            ]);
        } catch (Throwable $exception) {
            // Uma falha de gravação nunca deve liberar o envio da mensagem.
            report($exception);
        }
    }

    private function currentIpAddress(): ?string
    {
        try {
            return request()->ip();
        } catch (Throwable) {
            return null;
        }
    }
}
