<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthenticationLifecycleManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    /**
     * Revoga somente o token usado nesta requisição. Os efeitos de logout
     * (encerramento de consulta ativa e disponibilidade do atendente) são os
     * mesmos do fluxo web.
     */
    public function __invoke(
        Request $request,
        AuthenticationLifecycleManager $authentication,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $authentication->afterLogout($user);

        return response()->noContent();
    }
}
