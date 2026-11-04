<?php

namespace App\Http\Controllers\Attendant;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationLifecycleManager;
use App\Support\ConsultationRequestPresenter;
use App\Support\ConsultationStatePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsultationController extends Controller
{
    /**
     * Aceite da solicitação. O atendente cai direto na tela dedicada de
     * atendimento; requisições JSON (modal em tempo real) recebem o destino em
     * `redirect` para que o navegador navegue sem recarregar o painel antes.
     */
    public function accept(
        Request $request,
        Consultation $consultation,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('accept', $consultation);

        $wasActive = $consultation->status === ConsultationStatus::Active;

        $accepted = $lifecycle->accept($request->user(), $consultation);
        $message = $wasActive
            ? 'Esta consulta já estava em andamento.'
            : 'Consulta aceita. O atendimento foi iniciado.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => route('consultations.workspace', $accepted),
                'consultation' => ConsultationStatePresenter::toArray($accepted),
            ]);
        }

        return redirect()
            ->route('consultations.workspace', $accepted)
            ->with('success', $message);
    }

    public function reject(
        Request $request,
        Consultation $consultation,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('reject', $consultation);

        $wasRejected = $consultation->status === ConsultationStatus::Rejected;

        $rejected = $lifecycle->reject($request->user(), $consultation);
        $message = $wasRejected
            ? 'Esta solicitação já havia sido recusada.'
            : 'Solicitação recusada.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => route('consultations.show', $rejected),
                'consultation' => ConsultationStatePresenter::toArray($rejected),
            ]);
        }

        return redirect()
            ->route('consultations.show', $consultation)
            ->with('success', $message);
    }

    /**
     * Recuperação das solicitações pendentes.
     *
     * O painel abre o canal privado do usuário e, a cada conexão (inclusive
     * depois de queda de rede ou suspensão do notebook), sincroniza por aqui.
     * É uma leitura pontual por evento — não é polling.
     */
    public function pending(Request $request): JsonResponse
    {
        Gate::authorize('viewAttendantPanel', Consultation::class);

        /** @var User $user */
        $user = $request->user();

        $requests = $user->attendantConsultations()
            ->with(['client', 'attendant.attendantProfile'])
            ->where('status', ConsultationStatus::Requested->value)
            ->oldest()
            ->get()
            ->map(fn (Consultation $consultation): array => ConsultationRequestPresenter::toArray($consultation))
            ->values();

        return response()->json(['requests' => $requests]);
    }
}
