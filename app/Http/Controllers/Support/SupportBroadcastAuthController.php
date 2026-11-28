<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Support\SupportChat\SupportVisitorIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Autorização dos canais privados do visitante.
 *
 * O Laravel Echo envia `socket_id` e `channel_name` para este endpoint porque o
 * visitante não tem sessão autenticada. A autorização compara o UUID do canal
 * com o UUID do cookie e revalida o hash do token; qualquer divergência nega.
 */
class SupportBroadcastAuthController extends Controller
{
    public function __invoke(Request $request, SupportVisitorIdentity $identity): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'string', 'max:120'],
        ]);

        $conversation = $identity->current($request);

        abort_if($conversation === null, 403);

        $expectedChannel = 'private-support.conversation.'.$conversation->uuid;

        abort_unless(hash_equals($expectedChannel, (string) $validated['channel_name']), 403);

        /*
         * O resolver devolve a própria conversa como "usuário" do canal. Assim
         * o callback em routes/channels.php valida o UUID sem inventar uma
         * sessão ou gravar autenticação no guard web.
         */
        $request->setUserResolver(fn (): SupportConversation => $conversation);

        return response()->json(Broadcast::auth($request));
    }
}
