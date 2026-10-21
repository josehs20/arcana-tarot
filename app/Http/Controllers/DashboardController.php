<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(): View
    {
        return view('dashboard.admin');
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
