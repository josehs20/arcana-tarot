<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationInbox;
use App\Support\ConsultationRequestPresenter;
use App\Support\ConsultationStatePresenter;
use App\Support\Realtime\RealtimeMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Feed do painel para quando não existe WebSocket disponível.
 *
 * Devolve, para o próprio usuário autenticado, duas leituras:
 *  - `requests`: solicitações pendentes do atendente (abre o modal e a sirene);
 *  - `consultation`: a consulta aberta do usuário, que revela o aceite, o
 *    encerramento e a mudança de estado sem recarregar a página.
 *
 * É somente leitura: nada aqui grava atividade, presença, mensagens lidas ou
 * cobrança. O administrador não tem canal de avisos (o layout administrativo
 * não inclui o bloco), então a rota também nega acesso a ele.
 */
class PanelEventsController extends Controller
{
    public function __invoke(Request $request, ConsultationInbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $canAttend = Gate::allows('viewAttendantPanel', Consultation::class);
        $canRequest = Gate::allows('viewClientPanel', Consultation::class);

        abort_unless($canAttend || $canRequest, 403);

        $openConsultation = $inbox->openFor($user);

        $requests = $canAttend
            ? $inbox->pendingForAttendant($user)
                ->map(fn (Consultation $consultation): array => ConsultationRequestPresenter::toArray($consultation))
                ->values()
            : collect();

        return response()
            ->json([
                'requests' => $requests,
                'consultation' => $openConsultation === null
                    ? null
                    : ConsultationStatePresenter::toArray($openConsultation),
                'realtime_mode' => RealtimeMode::current(),
            ])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
