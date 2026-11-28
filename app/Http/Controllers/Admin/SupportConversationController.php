<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupportSenderType;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\SupportChat\SupportConversationManager;
use App\Support\SupportChat\SupportConversationPresenter;
use App\Support\SupportChat\SupportInbox;
use App\Support\SupportChat\SupportMessagePresenter;
use App\Support\SupportChat\SupportPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Caixa de entrada do suporte no painel administrativo.
 *
 * Somente administradores acessam estas rotas (middleware + policy de painel).
 * A fila é entregue como JSON e o widget mantém as abas no navegador; não há
 * página separada de suporte.
 */
class SupportConversationController extends Controller
{
    public function index(Request $request, SupportInbox $inbox): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $status = in_array($status, ['open', 'closed', 'all'], true) ? $status : 'open';

        $conversations = match ($status) {
            'closed' => $inbox->closedConversations(),
            'all' => $inbox->openConversations()->concat($inbox->closedConversations()),
            default => $inbox->openConversations(),
        };

        return response()->json([
            'conversations' => $conversations
                ->map(fn (SupportConversation $conversation): array => SupportConversationPresenter::forOperator($conversation))
                ->values()
                ->all(),
            'summary' => $inbox->summary(),
            'filter' => $status,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(
        Request $request,
        SupportConversation $conversation,
        SupportInbox $inbox,
    ): JsonResponse {
        $afterId = max(0, (int) $request->query('after_id', 0));
        $limit = $afterId > 0
            ? (int) config('support.incremental_limit', 100)
            : (int) config('support.history_limit', 100);

        $messages = $this->messages($conversation, $afterId, $limit);
        $conversation->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('sender_type', SupportSenderType::Visitor->value)
                ->whereNull('read_at'),
        ]);

        return response()->json([
            'conversation' => SupportConversationPresenter::forOperator($conversation),
            'messages' => $messages
                ->map(fn (SupportMessage $message): array => SupportMessagePresenter::forOperator($message))
                ->values()
                ->all(),
            'has_more' => $messages->count() >= $limit,
            'summary' => $inbox->summary(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function close(
        SupportConversation $conversation,
        SupportConversationManager $manager,
        SupportInbox $inbox,
    ): JsonResponse {
        $closed = $manager->close($conversation);

        return response()->json([
            'conversation' => SupportConversationPresenter::forOperator($closed),
            'summary' => $inbox->summary(),
        ]);
    }

    public function presence(SupportPresence $presence, Request $request): JsonResponse
    {
        $presence->markOnline($request->user());

        return response()->json([
            'online' => true,
            'heartbeat_seconds' => (int) config('support.presence_heartbeat_seconds', 45),
        ]);
    }

    /**
     * @return Collection<int, SupportMessage>
     */
    private function messages(SupportConversation $conversation, int $afterId, int $limit): Collection
    {
        $query = $conversation->messages()->with('sender')->orderBy('id');

        if ($afterId > 0) {
            return $query->where('id', '>', $afterId)->limit($limit)->get();
        }

        return $conversation->messages()
            ->with('sender')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
