<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Support\ConsultationPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class AttendantDirectoryController extends Controller
{
    public function index(ConsultationPricing $pricing): View
    {
        $attendants = $this->directoryQuery()
            ->published()
            ->orderForDirectory()
            ->get();

        return view('public.attendants.index', [
            'attendants' => $attendants,
            'pricePerMinute' => $pricing->pricePerMinuteLabel(),
        ]);
    }

    public function show(int $attendant, ConsultationPricing $pricing): View
    {
        $profile = $this->directoryQuery()
            ->published()
            ->findOrFail($attendant);

        return view('public.attendants.show', [
            'attendant' => $profile,
            'pricePerMinute' => $pricing->pricePerMinuteLabel(),
        ]);
    }

    /**
     * Somente colunas públicas: nunca carrega e-mail, comissão ou dados financeiros.
     *
     * @return Builder<AttendantProfile>
     */
    private function directoryQuery(): Builder
    {
        return AttendantProfile::query()
            ->select(['id', 'display_name', 'bio', 'specialties', 'photo_path', 'availability'])
            ->withExists('activeConsultation');
    }
}
