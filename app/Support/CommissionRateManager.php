<?php

namespace App\Support;

use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Configuração administrativa da taxa de comissão do atendente.
 *
 * A taxa efetiva é sempre explícita no perfil. Nenhuma alteração toca
 * consultas já iniciadas: elas permanecem com o snapshot do aceite.
 */
final class CommissionRateManager
{
    public function update(User $attendant, User $admin, int $rateBps): AttendantProfile
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem alterar taxas de comissão.');
        }

        if (! $attendant->isAttendant()) {
            throw new DomainException('A taxa de comissão é exclusiva de atendentes.');
        }

        if ($rateBps < 0 || $rateBps > 10000) {
            throw new InvalidArgumentException('A taxa de comissão deve estar entre 0,00% e 100,00%.');
        }

        return DB::transaction(function () use ($attendant, $admin, $rateBps): AttendantProfile {
            $profile = AttendantProfile::query()
                ->where('user_id', $attendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw new DomainException('O atendente ainda não possui perfil profissional.');
            }

            $previous = $profile->commission_rate_bps;

            if ($previous === $rateBps) {
                return $profile;
            }

            $profile->commission_rate_bps = $rateBps;
            $profile->save();

            AuditLog::query()->create([
                'actor_id' => $admin->getKey(),
                'action' => 'attendant.commission_rate_updated',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => $profile->getKey(),
                'metadata' => [
                    'attendant_id' => $attendant->getKey(),
                    'before' => $previous,
                    'after' => $rateBps,
                    'before_percent' => Rate::formatBps($previous),
                    'after_percent' => Rate::formatBps($rateBps),
                ],
            ]);

            return $profile;
        });
    }
}
