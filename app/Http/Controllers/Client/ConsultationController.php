<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreConsultationRequest;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationLifecycleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsultationController extends Controller
{
    public function store(
        StoreConsultationRequest $request,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse {
        Gate::authorize('create', Consultation::class);

        /** @var User $client */
        $client = $request->user();
        $attendant = User::query()->findOrFail($request->integer('attendant_id'));

        $modality = ConsultationModality::tryFrom((string) $request->input('modality', ConsultationModality::Text->value))
            ?? ConsultationModality::Text;

        $consultation = $lifecycle->request($client, $attendant, $modality);

        return redirect()
            ->route('consultations.show', $consultation)
            ->with('success', 'Solicitação de atendimento por '.$modality->label().' enviada. Aguarde o aceite do atendente.');
    }

    public function cancel(
        Request $request,
        Consultation $consultation,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse {
        Gate::authorize('cancel', $consultation);

        $wasCancelled = $consultation->status === ConsultationStatus::Cancelled;

        $lifecycle->cancel($request->user(), $consultation);

        return redirect()
            ->route('consultations.show', $consultation)
            ->with('success', $wasCancelled
                ? 'Esta solicitação já havia sido cancelada.'
                : 'Solicitação cancelada.');
    }
}
