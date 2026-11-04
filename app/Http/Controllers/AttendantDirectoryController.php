<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Support\ConsultationModalityCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class AttendantDirectoryController extends Controller
{
    public function index(ConsultationModalityCatalog $catalog): View
    {
        $attendants = $this->directoryQuery()
            ->published()
            ->orderForDirectory()
            ->get();

        return view('public.attendants.index', [
            'attendants' => $attendants,
            'modalityOptions' => $attendants->mapWithKeys(
                fn (AttendantProfile $profile): array => [
                    $profile->getKey() => $catalog->publicOptionsFor($profile),
                ],
            ),
        ]);
    }

    public function show(int $attendant, ConsultationModalityCatalog $catalog): View
    {
        $profile = $this->directoryQuery()
            ->published()
            ->findOrFail($attendant);

        return view('public.attendants.show', [
            'attendant' => $profile,
            'options' => $catalog->options($profile),
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
            ->select(['id', 'user_id', 'display_name', 'bio', 'specialties', 'offered_modalities', 'photo_path', 'availability'])
            ->with('modalityPrices')
            ->withExists('activeConsultation');
    }
}
