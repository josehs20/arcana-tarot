<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Support\ConsultationPricing;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(ConsultationPricing $pricing): View
    {
        $attendants = AttendantProfile::query()
            ->select(['id', 'display_name', 'bio', 'specialties', 'photo_path', 'availability'])
            ->withExists('activeConsultation')
            ->available()
            ->orderForDirectory()
            ->limit(6)
            ->get();

        return view('welcome', [
            'attendants' => $attendants,
            'pricePerMinute' => $pricing->pricePerMinuteLabel(),
        ]);
    }
}
