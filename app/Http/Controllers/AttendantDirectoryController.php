<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Support\AttendantDirectoryQuery;
use App\Support\ConsultationModalityCatalog;
use App\Support\ConsultationPricing;
use Illuminate\View\View;

class AttendantDirectoryController extends Controller
{
    public function index(
        ConsultationModalityCatalog $catalog,
        AttendantDirectoryQuery $directory,
    ): View {
        $attendants = $directory->query()->get();

        return view('public.attendants.index', [
            'attendants' => $attendants,
            'modalityOptions' => $attendants->mapWithKeys(
                fn (AttendantProfile $profile): array => [
                    $profile->getKey() => $catalog->publicOptionsFor($profile),
                ],
            ),
        ]);
    }

    public function show(
        int $attendant,
        ConsultationModalityCatalog $catalog,
        ConsultationPricing $pricing,
        AttendantDirectoryQuery $directory,
    ): View {
        $profile = $directory->findPublishedOrFail($attendant);

        /*
         * Depoimentos reais de atendimentos concluídos. Apenas os visíveis
         * entram na média e na listagem; o nome exibido é sempre reduzido a
         * primeiro nome + inicial do sobrenome (ver SECURITY_PRIVACY.md).
         */
        $visibleTestimonials = $profile->testimonials()->visible();

        $testimonials = (clone $visibleTestimonials)
            ->with('client')
            ->latest('id')
            ->paginate(10, ['*'], 'depoimentos_page')
            ->withQueryString()
            ->fragment('depoimentos');

        $testimonialCount = (clone $visibleTestimonials)->count();

        return view('public.attendants.show', [
            'attendant' => $profile,
            'options' => $catalog->options($profile),
            /*
             * Minutos mínimos exigidos para iniciar (setting global). A view
             * combina esse número com o preço de cada modalidade para avisar o
             * cliente antes de enviar a solicitação; quem decide é o servidor.
             */
            'minimumBalanceMinutes' => $pricing->minimumBalanceMinutes(),
            'testimonials' => $testimonials,
            'testimonialCount' => $testimonialCount,
            'testimonialAverage' => $testimonialCount === 0
                ? null
                : round((float) (clone $visibleTestimonials)->avg('rating'), 1),
        ]);
    }
}
