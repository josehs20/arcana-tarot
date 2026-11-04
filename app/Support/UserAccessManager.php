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
 * Ativação e desativação de contas pelo painel administrativo.
 *
 * Desativar um atendente o força para offline e é bloqueado enquanto houver
 * consulta ativa, evitando que uma sessão em andamento fique sem contraparte.
 */
final class UserAccessManager
{
    public function setActive(User $target, bool $active, User $actor): User
    {
        return DB::transaction(function () use ($target, $active, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($locked->is_active === $active) {
                return $locked;
            }

            if (! $active) {
                $hasActiveConsultation = Consultation::query()
                    ->where('attendant_id', $locked->getKey())
                    ->where('status', ConsultationStatus::Active->value)
                    ->exists();

                if ($hasActiveConsultation) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Encerre a consulta ativa deste atendente antes de desativar a conta.',
                    ]);
                }
            }

            $from = $locked->is_active;

            $locked->is_active = $active;
            $locked->save();

            if (! $active) {
                AttendantProfile::query()
                    ->where('user_id', $locked->getKey())
                    ->update(['availability' => AttendantAvailability::Offline->value]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => $active ? 'user.activated' : 'user.deactivated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'from' => $from,
                    'to' => $active,
                    'role' => $locked->role->value,
                ],
            ]);

            return $locked;
        });
    }
}
