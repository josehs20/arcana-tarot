<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendantApplicationReview
{
    public function start(AttendantApplication $application, User $admin, ?string $note = null): AttendantApplication
    {
        return $this->transition(
            $application,
            ApplicationStatus::UnderReview,
            $admin,
            $note,
            'attendant_application.under_review',
        );
    }

    public function approve(AttendantApplication $application, User $admin, ?string $note = null): AttendantApplication
    {
        return $this->transition(
            $application,
            ApplicationStatus::Approved,
            $admin,
            $note,
            'attendant_application.approved',
        );
    }

    public function reject(AttendantApplication $application, User $admin, ?string $note = null): AttendantApplication
    {
        return $this->transition(
            $application,
            ApplicationStatus::Rejected,
            $admin,
            $note,
            'attendant_application.rejected',
        );
    }

    /**
     * Executa a transição com bloqueio de linha e grava auditoria sem o
     * conteúdo da candidatura (apenas origem/destino do estado).
     */
    private function transition(
        AttendantApplication $application,
        ApplicationStatus $target,
        User $admin,
        ?string $note,
        string $action,
    ): AttendantApplication {
        return DB::transaction(function () use ($application, $target, $admin, $note, $action) {
            $locked = AttendantApplication::query()
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if (! $this->canTransition($locked->status, $target)) {
                throw ValidationException::withMessages([
                    'status' => 'Esta candidatura não está mais disponível para esta ação. Atualize a página e tente novamente.',
                ]);
            }

            $from = $locked->status;
            $now = now();

            $locked->status = $target;
            $locked->status_changed_at = $now;
            $locked->reviewed_by = $admin->getKey();

            if ($target === ApplicationStatus::UnderReview) {
                $locked->review_started_at = $now;
            } else {
                $locked->reviewed_at = $now;
            }

            if (filled($note)) {
                $locked->review_notes = trim((string) $note);
            }

            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $admin->getKey(),
                'action' => $action,
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'from' => $from->value,
                    'to' => $target->value,
                ],
            ]);

            return $locked->load('reviewer');
        });
    }

    private function canTransition(ApplicationStatus $from, ApplicationStatus $target): bool
    {
        if ($target === ApplicationStatus::UnderReview) {
            return $from === ApplicationStatus::Pending;
        }

        if (in_array($target, [ApplicationStatus::Approved, ApplicationStatus::Rejected], true)) {
            return in_array($from, [ApplicationStatus::Pending, ApplicationStatus::UnderReview], true);
        }

        return false;
    }
}
