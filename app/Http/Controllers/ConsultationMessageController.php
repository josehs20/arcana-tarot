<?php

namespace App\Http\Controllers;

use App\Enums\MessageType;
use App\Http\Requests\StoreConsultationCardRequest;
use App\Http\Requests\StoreConsultationMessageRequest;
use App\Http\Requests\StoreConsultationVoiceRequest;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\User;
use App\Support\ConsultationCardImageStorage;
use App\Support\ConsultationMessageManager;
use App\Support\ConsultationMessagePresenter;
use App\Support\ConsultationStatePresenter;
use App\Support\ConsultationVoiceStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    /**
     * Envio de uma gravação de voz no atendimento por áudio.
     *
     * O arquivo é gravado no disco privado antes da transação; se a mensagem
     * não puder ser criada, o áudio recém-gravado é descartado. O caminho e a
     * extensão nunca vêm do navegador.
     */
    public function storeVoice(
        StoreConsultationVoiceRequest $request,
        Consultation $consultation,
        ConsultationMessageManager $messages,
        ConsultationVoiceStorage $voices,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('sendVoice', $consultation);

        /** @var User $user */
        $user = $request->user();

        $extension = $request->extension();

        if ($extension === null) {
            throw ValidationException::withMessages([
                'audio' => 'O formato da gravação não é suportado.',
            ]);
        }

        $path = $voices->store($consultation, $request->audioFile(), $extension);

        $message = $messages->sendVoice($consultation, $user, $path, $request->durationSeconds());

        return $this->respondWithMessage($consultation, $message, 'Áudio enviado.');
    }

    /**
     * Entrega a gravação de voz guardada no disco privado.
     *
     * O player do chat aponta para esta rota; a participação na consulta é
     * revalidada a cada requisição, como nas imagens das cartas.
     */
    public function audio(
        Consultation $consultation,
        ConsultationMessage $message,
        ConsultationVoiceStorage $voices,
    ): StreamedResponse {
        Gate::authorize('viewMessages', $consultation);

        abort_unless($message->consultation_id === $consultation->getKey(), 404);
        abort_unless($message->type === MessageType::Voice, 404);

        return $voices->response($message);
    }

    public function image(
        Consultation $consultation,
        ConsultationMessage $message,
        ConsultationCardImageStorage $images,
    ): StreamedResponse {
        $this->authorizeCardMessage($consultation, $message);

        return $images->response($message);
    }

    /**
     * Miniatura da carta exibida no balão do chat.
     *
     * Mesma autorização da imagem cheia — participação na consulta e mensagem
     * do tipo carta desta consulta —, mas entrega o WebP reduzido. A imagem
     * original só é baixada quando o usuário abre o link.
     */
    public function imageThumbnail(
        Consultation $consultation,
        ConsultationMessage $message,
        ConsultationCardImageStorage $images,
    ): StreamedResponse {
        $this->authorizeCardMessage($consultation, $message);

        return $images->thumbnailResponse($message);
    }

    /**
     * Portão comum das rotas de imagem: nunca aceita mensagem de outra consulta
     * nem mensagem de texto.
     */
    private function authorizeCardMessage(Consultation $consultation, ConsultationMessage $message): void
    {
        Gate::authorize('viewMessages', $consultation);

        abort_unless($message->consultation_id === $consultation->getKey(), 404);
        abort_unless($message->type === MessageType::TarotCard, 404);
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
