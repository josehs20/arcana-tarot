<?php

namespace App\Http\Controllers;

use App\Enums\MessageType;
use App\Http\Requests\StoreConsultationCardRequest;
use App\Http\Requests\StoreConsultationMessageRequest;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\User;
use App\Support\ConsultationCardImageStorage;
use App\Support\ConsultationMessageManager;
use App\Support\ConsultationMessagePresenter;
use App\Support\ConsultationStatePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsultationMessageController extends Controller
{
    /**
     * Recupera mensagens posteriores ao último ID conhecido pelo navegador.
     *
     * É o fallback para reconexão do WebSocket e para eventos eventualmente
     * perdidos. O histórico persistido continua sendo a fonte de verdade.
     */
    public function index(Request $request, Consultation $consultation): JsonResponse
    {
        Gate::authorize('viewMessages', $consultation);

        $validated = $request->validate([
            'after_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $afterId = (int) ($validated['after_id'] ?? 0);
        $limit = 100;

        $messages = $consultation->messages()
            ->with(['sender.attendantProfile', 'consultation.attendant.attendantProfile'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'messages' => $messages
                ->map(fn (ConsultationMessage $message): array => ConsultationMessagePresenter::toArray($message))
                ->values(),
            'last_message_id' => $messages->last()?->getKey() ?? $afterId,
            'has_more' => $messages->count() === $limit,
            'consultation' => ConsultationStatePresenter::toArray($consultation),
        ]);
    }

    /**
     * Marca a conversa como lida após uma mensagem recebida em tempo real.
     */
    public function markRead(Request $request, Consultation $consultation): Response
    {
        Gate::authorize('viewMessages', $consultation);

        /** @var User $user */
        $user = $request->user();

        $consultation->markMessagesReadBy($user);

        return response()->noContent();
    }

    public function store(
        StoreConsultationMessageRequest $request,
        Consultation $consultation,
        ConsultationMessageManager $messages,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('sendMessage', $consultation);

        /** @var User $user */
        $user = $request->user();

        $message = $messages->sendText($consultation, $user, (string) $request->string('body'));

        return $this->respondWithMessage($consultation, $message, 'Mensagem enviada.');
    }

    public function storeCard(
        StoreConsultationCardRequest $request,
        Consultation $consultation,
        ConsultationMessageManager $messages,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('sendCard', $consultation);

        /** @var User $user */
        $user = $request->user();

        $sent = $messages->sendCards($consultation, $user, $request->cardIds());

        $notice = $sent->count() === 1
            ? 'Carta enviada.'
            : $sent->count().' cartas enviadas.';

        return $this->respondWithMessages($consultation, $sent, $notice);
    }

    public function image(
        Consultation $consultation,
        ConsultationMessage $message,
        ConsultationCardImageStorage $images,
    ): StreamedResponse {
        Gate::authorize('viewMessages', $consultation);

        abort_unless($message->consultation_id === $consultation->getKey(), 404);
        abort_unless($message->type === MessageType::TarotCard, 404);

        return $images->response($message);
    }

    /**
     * Envio idempotente para os dois públicos.
     *
     * O formulário HTML continua funcionando (redirect + flash). O chat em
     * tempo real envia a mesma rota via fetch com `Accept: application/json`
     * e recebe a mensagem já persistida, que é inserida na linha do tempo sem
     * recarregar a página. O broadcast só acontece depois do commit.
     */
    private function respondWithMessage(
        Consultation $consultation,
        ConsultationMessage $message,
        string $notice,
    ): RedirectResponse|JsonResponse {
        if (request()->wantsJson()) {
            return response()->json([
                'message' => ConsultationMessagePresenter::toArray($message),
                'consultation' => ConsultationStatePresenter::toArray($consultation->refresh()),
                'notice' => $notice,
            ], 201);
        }

        return $this->backToChat($consultation, $notice);
    }

    private function backToChat(Consultation $consultation, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('consultations.show', $consultation).'#chat')
            ->with('success', $message);
    }

    /**
     * Resposta do envio de cartas: o JSON traz a lista completa de mensagens
     * criadas (o chat insere todas de uma vez) e mantém a chave `message` com a
     * primeira delas para clientes antigos.
     *
     * @param  Collection<int, ConsultationMessage>  $sent
     */
    private function respondWithMessages(
        Consultation $consultation,
        Collection $sent,
        string $notice,
    ): RedirectResponse|JsonResponse {
        if (request()->wantsJson()) {
            $messages = $sent
                ->map(fn (ConsultationMessage $message): array => ConsultationMessagePresenter::toArray($message))
                ->values();

            return response()->json([
                'messages' => $messages,
                'message' => $messages->first(),
                'consultation' => ConsultationStatePresenter::toArray($consultation->refresh()),
                'notice' => $notice,
            ], 201);
        }

        return $this->backToChat($consultation, $notice);
    }
}
