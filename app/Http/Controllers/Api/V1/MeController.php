<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Identidade do usuário autenticado pelo Bearer token.
     */
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->is_active, 403, 'Conta inativa.');

        return new UserResource($user);
    }
}
