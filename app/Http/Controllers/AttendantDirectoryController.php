<?php

namespace App\Http\Controllers;

use App\Models\AttendantProfile;
use App\Support\ConsultationModalityCatalog;
use App\Support\ConsultationPricing;
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

    public function show(int $attendant, ConsultationModalityCatalog $catalog, ConsultationPricing $pricing): View
    {
        $profile = $this->directoryQuery()
            ->published()
            ->findOrFail($attendant);

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
