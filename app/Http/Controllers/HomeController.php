<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Models\Product;
use App\Models\TarotDeck;
use App\Support\ConsultationModalityCatalog;
use App\Support\FirstAccessBonus;
use App\Support\ProductCheckoutManager;
use App\Support\PromotionManager;
use App\Support\SiteContent;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(
        ConsultationModalityCatalog $catalog,
        SiteContent $site,
        PromotionManager $promotions,
        ProductCheckoutManager $productCheckout,
        FirstAccessBonus $firstAccessBonus,
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

        $products = Product::query()
            ->active()
            ->ordered()
            ->limit(6)
            ->get();

        return view('welcome', [
            'attendants' => $attendants,
            'tarotDecks' => $tarotDecks,
            'products' => $products,
            'productCheckoutEnabled' => $productCheckout->enabled(),
            'promotions' => $promotions->active(),
            // Faixa de bônus exibida logo abaixo do topo da home. Segue o
            // mesmo interruptor de /admin/financeiro/bonus e aparece para
            // todos os visitantes.
            'registrationBonus' => $firstAccessBonus->signupOffer(),
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
