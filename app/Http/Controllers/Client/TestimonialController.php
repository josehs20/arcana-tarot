<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreConsultationTestimonialRequest;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationTestimonial;
use App\Models\User;
use App\Support\Privacy\SensitiveDataDetector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Depoimentos do cliente sobre atendimentos concluídos.
 *
 * A listagem separa o que ainda pode ser avaliado do que já foi enviado. O
 * envio é único por consulta e só existe para status `ended`: interrupções,
 * cancelamentos, recusas e expirações nunca aparecem aqui.
 */
class TestimonialController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access-client-panel');
        Gate::authorize('viewClientPanel', Consultation::class);

        /** @var User $user */
        $user = $request->user();

        $consultations = $user->clientConsultations()
            ->where('status', ConsultationStatus::Ended->value)
            ->with(['attendant.attendantProfile', 'testimonial'])
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->get();

        $pending = $consultations
            ->filter(fn (Consultation $consultation): bool => $consultation->testimonial === null)
            ->values();

        $submitted = $consultations
            ->filter(fn (Consultation $consultation): bool => $consultation->testimonial !== null)
            ->sortByDesc(fn (Consultation $consultation): int => (int) $consultation->testimonial->created_at?->getTimestamp())
            ->values();

        $ratedTestimonials = $submitted
            ->map(fn (Consultation $consultation): int => $consultation->testimonial->rating);

        return view('client.testimonials', [
            'pending' => $pending,
            'submitted' => $submitted,
            'averageRating' => $ratedTestimonials->isEmpty()
                ? null
                : round((float) $ratedTestimonials->avg(), 1),
        ]);
    }

    public function store(
        StoreConsultationTestimonialRequest $request,
        Consultation $consultation,
        SensitiveDataDetector $detector,
    ): RedirectResponse {
        Gate::authorize('writeTestimonial', $consultation);

        /** @var array{rating: int|string, message: string} $validated */
        $validated = $request->validated();

        // O depoimento fica público: contatos externos e documentos são
        // recusados aqui, igual ao chat, antes de qualquer gravação.
        $detection = $detector->detect($validated['message']);

        if ($detection->hasMatches()) {
            throw ValidationException::withMessages([
                'message' => 'O depoimento não pode conter dado pessoal ou contato externo ('
                    .$detection->summary()
                    .'). Fale apenas da sua experiência com o atendimento.',
            ]);
        }

        try {
            $testimonial = ConsultationTestimonial::query()->create([
                'consultation_id' => $consultation->getKey(),
                'client_id' => $consultation->client_id,
                'attendant_id' => $consultation->attendant_id,
                'rating' => (int) $validated['rating'],
                'message' => trim($validated['message']),
            ]);
        } catch (UniqueConstraintViolationException) {
            return redirect()
                ->route('client.testimonials.index')
                ->with('warning', 'Você já enviou um depoimento para esta consulta.');
        }

        AuditLog::query()->create([
            'actor_id' => $request->user()?->getKey(),
            'action' => 'consultation_testimonial.created',
            'subject_type' => $testimonial->getMorphClass(),
            'subject_id' => $testimonial->getKey(),
            'metadata' => [
                'consultation_id' => $consultation->getKey(),
                'client_id' => $consultation->client_id,
                'attendant_id' => $consultation->attendant_id,
                'rating' => $testimonial->rating,
            ],
        ]);

        return redirect()
            ->back(fallback: route('client.testimonials.index'))
            ->with('success', 'Depoimento enviado. Obrigado por compartilhar sua experiência!');
    }
}
