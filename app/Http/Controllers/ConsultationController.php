<?php

namespace App\Http\Controllers;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Support\ConsultationActivityTracker;
use App\Support\ConsultationLifecycleManager;
use App\Support\ConsultationMetricsPresenter;
use App\Support\ConsultationPartyPresenter;
use App\Support\ConsultationSafety;
use App\Support\CreditWalletManager;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function show(Request $request, Consultation $consultation): View
    {
        Gate::authorize('view', $consultation);

        $consultation->loadMissing(['client', 'attendant.attendantProfile', 'call']);

        /** @var User $user */
        $user = $request->user();

        if ($consultation->isAttendant($user)) {
            $consultation->attendant->attendantProfile?->markSeen();
        }

        $chat = $this->chatData($consultation, $user);
        $isClient = $consultation->isClient($user);

        return view('consultations.show', [
            'consultation' => $consultation,
            'isClient' => $isClient,
            'isAttendant' => $consultation->isAttendant($user),
            'isAdmin' => $user->isAdmin(),
            'layoutVariant' => $user->isAdmin() ? 'admin' : 'panel',
            'canViewMessages' => $chat['canViewMessages'],
            'messages' => $chat['messages'],
            'unreadMessagesCount' => $chat['unreadMessagesCount'],
            'cardDecks' => $chat['cardDecks'],
            'parties' => ConsultationPartyPresenter::toArray($consultation, $user),
            'call' => $consultation->call,
            'canLeaveTestimonial' => $isClient && $consultation->allowsTestimonial(),
            'attendantName' => $consultation->attendant->attendantProfile?->display_name
                ?? $consultation->attendant->name,
            'callConfig' => $consultation->requiresCall()
                ? [
                    'ice_servers' => (array) config('consultation.web_rtc.ice_servers', []),
                    'poll_ms' => (int) config('consultation.web_rtc.signal_poll_ms', 2500),
                    'ring_timeout_seconds' => (int) config('consultation.web_rtc.ring_timeout_seconds', 90),
                ]
                : null,
        ]);
    }

    /**
     * Tela dedicada de atendimento.
     *
     * Substitui o painel cheio por um ambiente focado: identificação das
     * partes, horário da solicitação, saldo em créditos, valor cobrado,
     * comissão e chat em tempo real. Só existe depois do aceite; antes disso
     * (ou depois do encerramento) o usuário volta para a tela da consulta,
     * que concentra histórico e recibo.
     */
    public function workspace(
        Request $request,
        Consultation $consultation,
        ConsultationSafety $safety,
        CreditWalletManager $wallets,
        ConsultationLifecycleManager $lifecycle,
    ): View|RedirectResponse {
        Gate::authorize('view', $consultation);

        // Abrir a tela depois do prazo de inatividade também encerra: se o
        // scheduler estiver parado, a consulta não fica presa em `active`.
        $consultation = $lifecycle->interruptIfInactive($consultation);

        // Mesma rede de segurança para o dinheiro: sem o comando de cobrança, a
        // consulta sem saldo é encerrada aqui em vez de seguir de graça.
        $consultation = $lifecycle->closeIfOutOfCredits($consultation);

        /** @var User $user */
        $user = $request->user();

        if (! Gate::allows('workspace', $consultation)) {
            return redirect()
                ->route('consultations.show', $consultation)
                ->with('status', $this->workspaceUnavailableMessage($consultation));
        }

        $consultation->loadMissing(['client', 'attendant.attendantProfile', 'call']);

        if ($consultation->isAttendant($user)) {
            $consultation->attendant->attendantProfile?->markSeen();
        }

        $chat = $this->chatData($consultation, $user);
        $isClient = $consultation->isClient($user);

        return view('consultations.workspace', [
            'consultation' => $consultation,
            'isClient' => $isClient,
            'isAttendant' => $consultation->isAttendant($user),
            'canViewMessages' => $chat['canViewMessages'],
            'messages' => $chat['messages'],
            'unreadMessagesCount' => $chat['unreadMessagesCount'],
            'cardDecks' => $chat['cardDecks'],
            'call' => $consultation->call,
            'callConfig' => $consultation->requiresCall()
                ? [
                    'ice_servers' => (array) config('consultation.web_rtc.ice_servers', []),
                    'poll_ms' => (int) config('consultation.web_rtc.signal_poll_ms', 2500),
                    'ring_timeout_seconds' => (int) config('consultation.web_rtc.ring_timeout_seconds', 90),
                ]
                : null,
            'metrics' => ConsultationMetricsPresenter::toArray($consultation, $user),
            'parties' => ConsultationPartyPresenter::toArray($consultation, $user),
            'safety' => $safety->values(),
            'creditBalance' => $isClient ? $this->creditBalancePayload($user, $wallets) : null,
            'creditsUrl' => $isClient
                ? route('client.credits.index', ['return_consultation' => $consultation->getKey()])
                : null,
        ]);
    }

    /**
     * Números oficiais do atendimento para a tela dedicada.
     *
     * Consumido por fetch na abertura da tela, a cada limite de minuto e a
     * cada transição de estado. Todos os valores são calculados no servidor.
     */
    public function metrics(Request $request, Consultation $consultation): JsonResponse
    {
        Gate::authorize('view', $consultation);

        /** @var User $user */
        $user = $request->user();

        return response()->json(
            ConsultationMetricsPresenter::toArray($consultation, $user),
        );
    }

    /**
     * Batimento de presença do atendimento.
     *
     * O token é opaco e gerado pelo servidor; reenviá-lo reabre a mesma sessão
     * depois de uma queda de rede. `activity` só é verdadeiro em interações
     * reais (abrir a tela, digitar, enviar), nunca no batimento automático.
     *
     * Antes de renovar a sessão, o prazo de inatividade é conferido: vencido o
     * limite, a consulta é interrompida aqui mesmo e a resposta já volta com o
     * estado final, que leva a tela para o histórico. O mesmo acontece quando a
     * carteira do cliente não cobre mais os minutos iniciados: a consulta é
     * encerrada com `out_of_credits`, sem débito parcial e sem saldo negativo.
     */
    public function heartbeat(
        Request $request,
        Consultation $consultation,
        ConsultationActivityTracker $tracker,
        ConsultationSafety $safety,
        CreditWalletManager $wallets,
        ConsultationLifecycleManager $lifecycle,
    ): JsonResponse {
        Gate::authorize('view', $consultation);

        $validated = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'activity' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /*
         * O batimento da tela também renova a presença do atendente: é o sinal
         * usado para devolver o perfil ocupado a online quando a consulta
         * termina. Vem antes da checagem de inatividade para que a presença
         * esteja atualizada caso este mesmo batimento encerre o atendimento.
         */
        if ($consultation->isAttendant($user)) {
            $user->attendantProfile?->markSeen();
        }

        $consultation = $lifecycle->interruptIfInactive($consultation);

        /*
         * Antes de renovar a sessão, o saldo é conferido: quando a carteira não
         * cobre os minutos já iniciados, a consulta é encerrada aqui mesmo
         * (`out_of_credits`) e a resposta volta com o estado final. É o que
         * fecha o atendimento no Hostoo quando o cron de cobrança está parado
         * ou atrasado — sem depender do navegador.
         */
        $consultation = $lifecycle->closeIfOutOfCredits($consultation);

        $session = $consultation->isActive()
            ? $tracker->heartbeat(
                consultation: $consultation,
                user: $user,
                sessionToken: $validated['session_token'] ?? null,
                countsAsActivity: (bool) ($validated['activity'] ?? false),
            )
            : null;

        $consultation->refresh()->loadMissing(['client', 'attendant']);

        return response()->json([
            'consultation_id' => (int) $consultation->getKey(),
            'status' => $consultation->status->value,
            'ended_reason' => $consultation->ended_reason?->value,
            'session_token' => $session?->session_token,
            'presence' => $tracker->presence($consultation),
            'navigation_warning_seconds' => $safety->navigationWarningSeconds(),
            'inactivity_timeout_seconds' => $safety->inactivityTimeoutSeconds(),
            'credits' => $this->creditBalancePayload($user, $wallets),
            'credits_url' => $consultation->isClient($user)
                ? route('client.credits.index', ['return_consultation' => $consultation->getKey()])
                : null,
        ]);
    }

    /**
     * Registra a saída da tela sem encerrar o atendimento. O encerramento
     * automático só acontece depois do prazo de inatividade configurado.
     */
    public function disconnect(
        Request $request,
        Consultation $consultation,
        ConsultationActivityTracker $tracker,
    ): JsonResponse {
        Gate::authorize('view', $consultation);

        $validated = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $tracker->disconnect(
            consultation: $consultation,
            user: $user,
            sessionToken: $validated['session_token'] ?? null,
            reason: 'user_left',
        );

        return response()->json([
            'consultation_id' => (int) $consultation->getKey(),
            'status' => $consultation->status?->value,
            'disconnected' => true,
        ]);
    }

    /**
     * Saldo usado pelo botão de compra do chat e pelo aviso de saldo baixo.
     *
     * @return array{balance_cents: int, balance_label: string, low_balance: bool}|null
     */
    private function creditBalancePayload(User $user, CreditWalletManager $wallets): ?array
    {
        if (! $user->isClient()) {
            return null;
        }

        $wallet = $wallets->walletFor($user);
        $balanceCents = (int) $wallet->balance_cents;

        return [
            'balance_cents' => $balanceCents,
            'balance_label' => Money::format($balanceCents),
            'low_balance' => $balanceCents <= 0,
        ];
    }

    private function workspaceUnavailableMessage(Consultation $consultation): string
    {
        $status = $consultation->status ?? ConsultationStatus::Requested;

        return match (true) {
            $status === ConsultationStatus::Requested => 'A tela de atendimento abre automaticamente quando o atendente aceitar a solicitação.',
            $status->isFinal() => 'Este atendimento já foi encerrado. O histórico e o recibo continuam disponíveis na tela da consulta.',
            default => 'Este atendimento não está ativo neste momento.',
        };
    }

    /**
     * Dados do chat (histórico paginado, contador de novas e baralhos) usados
     * pela tela da consulta e pela tela dedicada de atendimento.
     *
     * @return array{
     *     canViewMessages: bool,
     *     messages: LengthAwarePaginator|null,
     *     unreadMessagesCount: int,
     *     cardDecks: Collection
     * }
     */
    private function chatData(Consultation $consultation, User $user): array
    {
        /*
         * O chat e as cartas pertencem ao atendimento por texto. Consultas de
         * audio/video usam a interface de chamada e nunca exibem o chat.
         */
        $canViewMessages = Gate::allows('viewMessages', $consultation)
            && ($consultation->modality?->allowsChat() ?? true);
        $messages = null;
        $unreadMessagesCount = 0;

        if ($canViewMessages) {
            $unreadMessagesCount = $consultation->unreadMessageCountFor($user);

            /** @var LengthAwarePaginator $messages */
            $messages = $consultation->messages()
                ->with('sender')
                ->orderByDesc('id')
                ->paginate((int) config('consultation.message_page_size'))
                ->withQueryString()
                ->fragment('chat');

            // A consulta é feita do mais recente para o mais antigo para a
            // paginação, mas a linha do tempo é exibida em ordem cronológica.
            $messages->setCollection($messages->getCollection()->reverse()->values());

            if ($unreadMessagesCount > 0) {
                $consultation->markMessagesReadBy($user);
            }
        }

        return [
            'canViewMessages' => $canViewMessages,
            'messages' => $messages,
            'unreadMessagesCount' => $unreadMessagesCount,
            'cardDecks' => Gate::allows('sendCard', $consultation)
                ? $this->availableCardDecks()
                : collect(),
        ];
    }

    /**
     * Baralhos com cartas ativas e arquivo de imagem presente. Somente o
     * atendente da consulta ativa recebe esta lista.
     */
    private function availableCardDecks(): Collection
    {
        return TarotDeck::query()
            ->active()
            ->whereHas('cards', fn (Builder $query) => $query->where('is_active', true))
            ->with(['cards' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(function (TarotDeck $deck): TarotDeck {
                $deck->setRelation(
                    'cards',
                    $deck->cards
                        ->filter(fn (TarotCard $card): bool => $card->hasStoredImage())
                        ->values(),
                );

                return $deck;
            })
            ->filter(fn (TarotDeck $deck): bool => $deck->cards->isNotEmpty())
            ->values();
    }

    public function end(
        Request $request,
        Consultation $consultation,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse {
        Gate::authorize('end', $consultation);

        $wasFinal = $consultation->status->isFinal();

        $lifecycle->end($request->user(), $consultation);

        return redirect()
            ->route('consultations.show', $consultation)
            ->with('success', $wasFinal
                ? 'Esta consulta já estava encerrada.'
                : 'Consulta encerrada.');
    }
}
