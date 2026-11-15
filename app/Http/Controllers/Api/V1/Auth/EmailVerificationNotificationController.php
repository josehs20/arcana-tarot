<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Reenvia o link de confirmação para o usuário autenticado. O limite é
     * aplicado na rota (`throttle:6,1`), como no fluxo web.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Seu e-mail já está confirmado.',
            ]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            abort(503, 'Não foi possível enviar o e-mail agora. Tente novamente em alguns minutos.');
        }

        return response()->json([
            'message' => 'Enviamos um novo link de confirmação para '.$user->email.'.',
        ]);
    }
}
