<?php

namespace App\Http\Controllers\Attendant;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\User;
use App\Support\CommissionSummary;
use App\Support\DashboardTrend;
use App\Support\ModalityUsageReport;
use App\Support\Rate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('access-attendant-panel');
        Gate::authorize('viewAttendantPanel', Consultation::class);

        /** @var User $user */
        $user = $request->user();
        $profile = $user->attendantProfile;

        if ($profile !== null) {
            Gate::authorize('view', $profile);
            $profile->markSeen();
        }

        $consultations = $user->attendantConsultations();

        $activeConsultation = (clone $consultations)
            ->with('client')
            ->where('status', ConsultationStatus::Active->value)
            ->first();

        $pendingRequests = (clone $consultations)
            ->with('client')
            ->where('status', ConsultationStatus::Requested->value)
            ->oldest()
            ->get();

        $history = (clone $consultations)
            ->with(['client', 'commissionEntries'])
            ->whereIn('status', ConsultationStatus::finalValues())
            ->latest()
            ->limit(10)
            ->get();

        $commissionEntries = $user->commissionEntries()
            ->with('consultation')
            ->latest('id')
            ->limit(10)
            ->get();

        $presence = 'offline';

        if ($profile !== null) {
            $presence = $activeConsultation !== null ? 'busy' : $profile->availability->value;
        }

        return view('dashboard.attendant', [
            'profile' => $profile,
            'presence' => $presence,
            'activeConsultation' => $activeConsultation,
            'pendingRequests' => $pendingRequests,
            'history' => $history,
            'completedConsultationsCount' => (clone $consultations)
                ->where('status', ConsultationStatus::Ended->value)
                ->count(),
            'commissionEntries' => $commissionEntries,
            'commissionSummary' => CommissionSummary::forAttendant($user),
            'commissionRateLabel' => $profile !== null
                ? Rate::formatBps($profile->commission_rate_bps)
                : null,
            'consultationTrend' => DashboardTrend::consultations(attendantId: $user->id),
            'modalityUsage' => ModalityUsageReport::summary(attendant: $user),
            'commissionTrend' => DashboardTrend::commissions($user),
            'unreadMessagesCount' => $activeConsultation?->unreadMessageCountFor($user) ?? 0,
        ]);
    }
}
