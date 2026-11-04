<?php

namespace App\Http\Controllers;

use App\Models\TarotCard;
use App\Support\TarotImageStorage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TarotCardImageController extends Controller
{
    public function __invoke(TarotCard $card, TarotImageStorage $images): StreamedResponse
    {
        Gate::authorize('viewImage', $card);

        return $images->response($card);
    }
}
