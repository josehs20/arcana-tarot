<?php

namespace App\Policies;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function viewClientPanel(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }

    public function viewAttendantPanel(User $user): bool
    {
        return $user->is_active && $user->isAttendant();
    }

    public function request(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }

    /**
     * Habilidade padrão de criação usada por Gate::authorize('create', ...).
     */
    public function create(User $user): bool
    {
        return $this->request($user);
    }

    public function view(User $user, Consultation $consultation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $consultation->hasParticipant($user);
    }

    public function accept(User $user, Consultation $consultation): bool
    {
        return $this->isActiveAttendantOf($user, $consultation)
            && in_array($consultation->status, [ConsultationStatus::Requested, ConsultationStatus::Active], true);
    }

    public function reject(User $user, Consultation $consultation): bool
    {
        return $this->isActiveAttendantOf($user, $consultation)
            && in_array($consultation->status, [ConsultationStatus::Requested, ConsultationStatus::Rejected], true);
    }

    public function cancel(User $user, Consultation $consultation): bool
    {
        return $this->isActiveClientOf($user, $consultation)
            && in_array($consultation->status, [ConsultationStatus::Requested, ConsultationStatus::Cancelled], true);
    }

    /**
     * Estados finais continuam autorizados para que o encerramento repetido
     * seja idempotente em vez de devolver 403.
     */
    public function end(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation)
            && ($consultation->status === ConsultationStatus::Active || $consultation->status?->isFinal());
    }

    /**
     * Tela dedicada de atendimento: apenas os dois participantes, e só depois
     * do aceite. O administrador continua com acesso aos metadados da consulta,
     * mas não ao ambiente de atendimento nem à conversa.
     */
    public function workspace(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation)
            && $consultation->status === ConsultationStatus::Active;
    }

    /**
     * Histórico do chat: somente cliente e atendente participantes. O
     * administrador vê os metadados da consulta, mas não a conversa.
     */
    public function viewMessages(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation);
    }

    public function sendMessage(User $user, Consultation $consultation): bool
    {
        return $this->viewMessages($user, $consultation)
            && $consultation->allowsMessaging()
            && ($consultation->modality?->allowsChat() ?? true);
    }

    /**
     * Áudio gravado no navegador: habilidade do atendimento por áudio, para os
     * dois participantes. O caminho do arquivo é sempre gerado no servidor.
     */
    public function sendVoice(User $user, Consultation $consultation): bool
    {
        return $this->viewMessages($user, $consultation)
            && $consultation->allowsVoiceMessages();
    }

    /**
     * Histórico completo no painel administrativo.
     *
     * Diferente de `viewMessages`, esta habilidade dá ao administrador leitura
     * da conversa para auditoria e acompanhamento de ocorrências. Cada acesso
     * é registrado em AuditLog pela controladora.
     */
    public function viewAdminHistory(User $user, Consultation $consultation): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function sendCard(User $user, Consultation $consultation): bool
    {
        return $this->isActiveAttendantOf($user, $consultation)
            && $consultation->allowsMessaging()
            && ($consultation->modality?->allowsTarotCards() ?? true);
    }

    /**
     * Depoimento pós-consulta: só o cliente do atendimento e só depois do
     * encerramento concluído (`ended`), com no máximo um depoimento por
     * consulta. Interrupções e cancelamentos nunca são avaliados.
     */
    public function writeTestimonial(User $user, Consultation $consultation): bool
    {
        return $this->isActiveClientOf($user, $consultation)
            && $consultation->allowsTestimonial();
    }

    /**
     * Chamada de video: os participantes continuam autorizados a ler o estado
     * depois do encerramento para que a interface exiba o resultado. O
     * atendimento por audio nao tem chamada. O manager revalida papel e estado
     * em cada transicao.
     */
    public function call(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation)
            && ($consultation->modality?->requiresCall() ?? false);
    }

    private function isActiveAttendantOf(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation)
            && $consultation->isAttendant($user);
    }

    private function isActiveClientOf(User $user, Consultation $consultation): bool
    {
        return $this->hasActiveParticipant($user, $consultation)
            && $consultation->isClient($user);
    }

    private function hasActiveParticipant(User $user, Consultation $consultation): bool
    {
        return $user->is_active && $consultation->hasParticipant($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
