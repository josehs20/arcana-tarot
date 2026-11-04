<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendantApplication;
use App\Models\Consultation;
use App\Support\AdminDashboardMetrics;
use App\Support\DashboardTrend;
use App\Support\ModalityUsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('access-admin-panel');

        return view('dashboard.admin', [
            'metrics' => AdminDashboardMetrics::collect(),
            'modalityUsage' => ModalityUsageReport::summary(),
            'consultationTrend' => DashboardTrend::consultations(),
            'rechargeTrend' => DashboardTrend::confirmedRecharges(),
            'recentConsultations' => Consultation::query()
                ->with(['client', 'attendant.attendantProfile'])
                ->latest()
                ->limit(6)
                ->get(),
            'recentApplications' => AttendantApplication::query()
                ->whereIn('status', [
                    ApplicationStatus::Pending->value,
                    ApplicationStatus::UnderReview->value,
                ])
                ->latest()
                ->limit(3)
                ->get(),
        ]);
    }
}
