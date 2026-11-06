<?php

namespace App\Http\Controllers;

use App\Models\TarotCard;
use App\Support\TarotImageStorage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Miniatura da carta para as grades (seletor do chat e catálogo).
 *
 * Mesma autorização da imagem cheia: a rota nunca expõe o caminho físico e
 * revalida a participação em consulta ativa a cada requisição.
 */
class TarotCardThumbnailController extends Controller
{
    public function __invoke(TarotCard $card, TarotImageStorage $images): StreamedResponse
    {
        Gate::authorize('viewImage', $card);

        return $images->thumbnailResponse($card);
    }
}
