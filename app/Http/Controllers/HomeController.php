<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Models\TarotDeck;
use App\Support\ConsultationModalityCatalog;
use App\Support\SiteContent;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(
        ConsultationModalityCatalog $catalog,
        SiteContent $site,
    ): View {
        $attendants = AttendantProfile::query()
            ->select(['id', 'user_id', 'display_name', 'bio', 'specialties', 'offered_modalities', 'photo_path', 'availability'])
            ->with('modalityPrices')
            ->withExists('activeConsultation')
            ->available()
            ->orderForDirectory()
            ->limit(6)
            ->get();

        $tarotDecks = TarotDeck::query()
            ->active()
            ->withCount('cards')
            ->orderBy('name')
            ->limit(6)
            ->get();

        return view('welcome', [
            'attendants' => $attendants,
            'tarotDecks' => $tarotDecks,
            'modalityOptions' => $attendants->mapWithKeys(
                fn (AttendantProfile $profile): array => [
                    $profile->getKey() => $catalog->publicOptionsFor($profile),
                ],
            ),
            'site' => $site,
            'sections' => $site->sections(),
            'faqs' => $site->faqs(),
        ]);
    }
}
