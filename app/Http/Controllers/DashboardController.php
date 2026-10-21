<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(): View
    {
        return view('dashboard.admin', [
            'pendingApplicationsCount' => AttendantApplication::query()
                ->where('status', ApplicationStatus::Pending->value)
                ->count(),
        ]);
    }

    public function attendant(): View
    {
        return view('dashboard.attendant');
    }

    public function client(): View
    {
        return view('dashboard.client');
    }
}
