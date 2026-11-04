<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Preferências de conversa do usuário autenticado.
 *
 * O cliente de chat lê os valores do atributo `data-*` do bloco `#chat` e
 * regrava a preferência aqui sem recarregar a página. Os dois campos são
 * enviados juntos para que o estado persistido seja sempre completo, mesmo
 * quando apenas um dos interruptores muda.
 */
class ChatPreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'send_on_enter' => ['required', 'boolean'],
            'sound_enabled' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->chat_send_on_enter = (bool) $validated['send_on_enter'];
        $user->chat_sound_enabled = (bool) $validated['sound_enabled'];
        $user->save();

        if ($request->expectsJson()) {
            return response()->json([
                'send_on_enter' => $user->chat_send_on_enter,
                'sound_enabled' => $user->chat_sound_enabled,
            ]);
        }

        return back();
    }
}
