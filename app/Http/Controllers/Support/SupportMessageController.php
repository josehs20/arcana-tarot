<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SendSupportMessageRequest;
use App\Support\SupportChat\SupportConversationManager;
use App\Support\SupportChat\SupportMessagePresenter;
use App\Support\SupportChat\SupportVisitorIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mensagens do visitante na conversa atual.
 *
 * O identificador da conversa vem do cookie assinado, nunca da URL: trocar o
 * corpo da requisição não permite escrever na conversa de outra pessoa.
 */
class SupportMessageController extends Controller
{
    public function store(
        SendSupportMessageRequest $request,
        SupportVisitorIdentity $identity,
        SupportConversationManager $manager,
    ): JsonResponse {
        $conversation = $identity->require($request);

        $message = $manager->postVisitorMessage($conversation, (string) $request->validated('body'));

        return response()->json([
            'message' => SupportMessagePresenter::forVisitor($message),
        ], 201);
    }

    public function markRead(
        Request $request,
        SupportVisitorIdentity $identity,
        SupportConversationManager $manager,
    ): JsonResponse {
        $conversation = $identity->require($request);

        $manager->markMessagesReadForVisitor($conversation);

        return response()->json(['unread_count' => 0]);
    }
}
