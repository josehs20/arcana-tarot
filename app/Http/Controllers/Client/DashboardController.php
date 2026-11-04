<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\Payment;
use App\Support\ConsultationPricing;
use App\Support\DashboardTrend;
use App\Support\ModalityUsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ConsultationPricing $pricing): View
    {
        Gate::authorize('access-client-panel');
        Gate::authorize('viewClientPanel', Consultation::class);
        Gate::authorize('viewAny', Payment::class);

        $user = $request->user();
        $wallet = $user->wallet;

        if ($wallet !== null) {
            Gate::authorize('view', $wallet);
        }

        $consultations = $user->clientConsultations();

        $currentConsultation = (clone $consultations)
            ->with('attendant.attendantProfile')
            ->whereIn('status', ConsultationStatus::openValues())
            ->orderByRaw('case when status = ? then 0 else 1 end', [ConsultationStatus::Active->value])
            ->latest()
            ->first();

        $history = (clone $consultations)
            ->with('attendant.attendantProfile')
            ->whereIn('status', ConsultationStatus::finalValues())
            ->latest()
            ->limit(10)
            ->get();

        $payments = $user->payments()
            ->latest()
            ->limit(10)
            ->get();

        $walletEntries = $wallet !== null
            ? $wallet->ledgerEntries()->latest('id')->limit(8)->get()
            : collect();

        $availableAttendantsQuery = AttendantProfile::query()
            ->available()
            ->whereDoesntHave('activeConsultation');

        $availableAttendants = (clone $availableAttendantsQuery)
            ->orderForDirectory()
            ->limit(6)
            ->get();

        return view('dashboard.client', [
            'wallet' => $wallet,
            'currentConsultation' => $currentConsultation,
            'history' => $history,
            'payments' => $payments,
            'walletEntries' => $walletEntries,
            'spendingTrend' => $wallet !== null
                ? DashboardTrend::consultationSpend($wallet)
                : ['labels' => [], 'values' => [], 'total' => 0, 'period' => ''],
            'availableAttendants' => $availableAttendants,
            'availableAttendantsCount' => (clone $availableAttendantsQuery)->count(),
            'consultationsCount' => (clone $consultations)->count(),
            'pricePerMinuteLabel' => $pricing->pricePerMinuteLabel(),
            'modalityUsage' => ModalityUsageReport::summary(client: $user),
            'unreadMessagesCount' => $currentConsultation?->unreadMessageCountFor($user) ?? 0,
        ]);
    }
}
