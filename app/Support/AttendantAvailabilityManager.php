<?php

namespace App\Support;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alteração da disponibilidade declarada do atendente.
 *
 * Regras:
 * - online, ocupado e offline são persistidos; "ocupado" também nasce do
 *   aceite de uma consulta, mas o atendente pode pausar a fila manualmente;
 * - com consulta ativa, a disponibilidade fica congelada até o encerramento;
 * - ficar online ou ocupado exige perfil publicado.
 */
final class AttendantAvailabilityManager
{
    public function set(User $attendant, AttendantAvailability $availability): AttendantProfile
    {
        return DB::transaction(function () use ($attendant, $availability): AttendantProfile {
            $profile = AttendantProfile::query()
                ->where('user_id', $attendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages([
                    'availability' => 'Seu perfil profissional ainda não foi criado. Fale com a administração.',
                ]);
            }

            $hasActiveConsultation = Consultation::query()
                ->where('attendant_id', $attendant->getKey())
                ->where('status', ConsultationStatus::Active->value)
                ->exists();

            if ($hasActiveConsultation) {
                throw ValidationException::withMessages([
                    'availability' => 'Você está em uma consulta ativa. O status "Ocupado" permanece até o encerramento.',
                ]);
            }

            if ($availability !== AttendantAvailability::Offline && ! $profile->is_published) {
                throw ValidationException::withMessages([
                    'availability' => 'Publique seu perfil profissional para ficar online ou ocupado.',
                ]);
            }

            $from = $profile->availability;

            if ($from === $availability) {
                if ($availability !== AttendantAvailability::Offline) {
                    $profile->markSeen();
                }

                return $profile;
            }

            $profile->availability = $availability;
            $profile->save();

            if ($availability !== AttendantAvailability::Offline) {
                $profile->markSeen();
            }

            AuditLog::query()->create([
                'actor_id' => $attendant->getKey(),
                'action' => 'attendant.availability_changed',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => $profile->getKey(),
                'metadata' => [
                    'from' => $from->value,
                    'to' => $availability->value,
                ],
            ]);

            return $profile;
        });
    }

    /**
     * Coloca o atendente online automaticamente ao entrar na conta.
     *
     * O login nunca é bloqueado por causa do status: perfil ausente, perfil não
     * publicado ou consulta ativa deixam a disponibilidade como está (a
     * consulta ativa congela o status até o encerramento). Quando o perfil já
     * está online, apenas a presença (`last_seen_at`) é renovada, sem gerar
     * auditoria duplicada. Um "ocupado" remanescente também volta a online,
     * porque o estado manual não sobrevive ao fim da sessão.
     */
    public function signIn(User $attendant): ?AttendantProfile
    {
        return DB::transaction(function () use ($attendant): ?AttendantProfile {
            $profile = AttendantProfile::query()
                ->where('user_id', $attendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                return null;
            }

            $hasActiveConsultation = Consultation::query()
                ->where('attendant_id', $attendant->getKey())
                ->where('status', ConsultationStatus::Active->value)
                ->exists();

            if ($hasActiveConsultation) {
                return $profile;
            }

            if ($profile->availability === AttendantAvailability::Online) {
                $profile->markSeen();

                return $profile;
            }

            if (! $profile->is_published) {
                return $profile;
            }

            $from = $profile->availability;

            $profile->availability = AttendantAvailability::Online;
            $profile->save();
            $profile->markSeen();

            AuditLog::query()->create([
                'actor_id' => $attendant->getKey(),
                'action' => 'attendant.availability_changed',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => $profile->getKey(),
                'metadata' => [
                    'from' => $from->value,
                    'to' => AttendantAvailability::Online->value,
                    'reason' => 'login',
                ],
            ]);

            return $profile;
        });
    }

    /**
     * Encerra a presença do atendente ao sair da conta.
     *
     * O logout nunca é bloqueado: sem perfil ou com consulta ativa, nada muda.
     * A consulta ativa é encerrada antes, em AuthenticatedSessionController,
     * para que o perfil volte a offline e não fique congelado esperando o
     * comando de inatividade. Um perfil ocupado também volta a offline: o
     * "ocupado" manual é um estado de sessão.
     */
    public function signOut(User $attendant): ?AttendantProfile
    {
        return DB::transaction(function () use ($attendant): ?AttendantProfile {
            $profile = AttendantProfile::query()
                ->where('user_id', $attendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null || $profile->availability === AttendantAvailability::Offline) {
                return $profile;
            }

            $hasActiveConsultation = Consultation::query()
                ->where('attendant_id', $attendant->getKey())
                ->where('status', ConsultationStatus::Active->value)
                ->exists();

            if ($hasActiveConsultation) {
                return $profile;
            }

            $from = $profile->availability;

            $profile->availability = AttendantAvailability::Offline;
            $profile->saveQuietly();

            AuditLog::query()->create([
                'actor_id' => $attendant->getKey(),
                'action' => 'attendant.availability_changed',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => $profile->getKey(),
                'metadata' => [
                    'from' => $from->value,
                    'to' => AttendantAvailability::Offline->value,
                    'reason' => 'logout',
                ],
            ]);

            return $profile;
        });
    }
}
