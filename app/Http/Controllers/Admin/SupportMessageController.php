<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SendSupportMessageRequest;
use App\Models\SupportConversation;
use App\Support\SupportChat\SupportConversationManager;
use App\Support\SupportChat\SupportConversationPresenter;
use App\Support\SupportChat\SupportInbox;
use App\Support\SupportChat\SupportMessagePresenter;
use Illuminate\Http\JsonResponse;

class SupportMessageController extends Controller
{
    public function store(
        SendSupportMessageRequest $request,
        SupportConversation $conversation,
        SupportConversationManager $manager,
        SupportInbox $inbox,
    ): JsonResponse {
        $message = $manager->postOperatorMessage(
            $conversation,
            $request->user(),
            (string) $request->validated('body'),
        );

        $conversation->refresh();

        return response()->json([
            'message' => SupportMessagePresenter::forOperator($message),
            'conversation' => SupportConversationPresenter::forOperator($conversation),
            'summary' => $inbox->summary(),
        ], 201);
    }

    public function markRead(
        SupportConversation $conversation,
        SupportConversationManager $manager,
        SupportInbox $inbox,
    ): JsonResponse {
        $manager->markMessagesReadForOperator($conversation);

        return response()->json([
            'unread_total' => $inbox->unreadTotal(),
        ]);
    }
}
