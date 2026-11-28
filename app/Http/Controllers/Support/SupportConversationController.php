<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StartSupportConversationRequest;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\SupportChat\SupportConversationManager;
use App\Support\SupportChat\SupportConversationPresenter;
use App\Support\SupportChat\SupportMessagePresenter;
use App\Support\SupportChat\SupportPresence;
use App\Support\SupportChat\SupportVisitorIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Conversa de suporte do visitante anônimo.
 *
 * O navegador é identificado somente pelo cookie HttpOnly; nenhum endpoint
 * aceita identificador de conversa enviado pelo cliente. A resposta nunca
 * contém token, e-mail ou qualquer dado de administrador.
 */
class SupportConversationController extends Controller
{
    public function show(
        Request $request,
        SupportVisitorIdentity $identity,
        SupportPresence $presence,
    ): JsonResponse {
        $conversation = $identity->current($request);

        if ($conversation === null) {
            return response()->json([
                'conversation' => null,
                'messages' => [],
                'has_more' => false,
                'availability' => $presence->status(),
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $afterId = max(0, (int) $request->query('after_id', 0));
        $limit = $afterId > 0
            ? (int) config('support.incremental_limit', 100)
            : (int) config('support.history_limit', 100);

        $messages = $this->messages($conversation, $afterId, $limit);

        return response()->json([
            'conversation' => SupportConversationPresenter::forVisitor($conversation),
            'messages' => $messages->map(fn (SupportMessage $message): array => SupportMessagePresenter::forVisitor($message))->values()->all(),
            'has_more' => $messages->count() >= $limit,
            'availability' => $presence->status(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function store(
        StartSupportConversationRequest $request,
        SupportVisitorIdentity $identity,
        SupportConversationManager $manager,
        SupportPresence $presence,
    ): JsonResponse {
        $existing = $identity->current($request);

        if ($existing !== null && $existing->isOpen()) {
            $messages = $this->messages($existing, 0, (int) config('support.history_limit', 100));

            return response()->json([
                'conversation' => SupportConversationPresenter::forVisitor($existing),
                'messages' => $messages->map(fn (SupportMessage $message): array => SupportMessagePresenter::forVisitor($message))->values()->all(),
                'has_more' => false,
                'availability' => $presence->status(),
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $result = $manager->start([
            'visitor_name' => (string) $request->validated('visitor_name'),
            'visitor_email' => $request->validated('visitor_email'),
            'body' => (string) $request->validated('body'),
        ]);

        $identity->remember($result['conversation'], $result['token']);

        return response()->json([
            'conversation' => SupportConversationPresenter::forVisitor($result['conversation']),
            'messages' => [SupportMessagePresenter::forVisitor($result['message'])],
            'has_more' => false,
            'availability' => $presence->status(),
            'server_time' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * @return Collection<int, SupportMessage>
     */
    private function messages(SupportConversation $conversation, int $afterId, int $limit): Collection
    {
        $query = $conversation->messages()->orderBy('id');

        if ($afterId > 0) {
            return $query->where('id', '>', $afterId)->limit($limit)->get();
        }

        return $conversation->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
