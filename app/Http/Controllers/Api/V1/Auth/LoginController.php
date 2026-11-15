<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\AuthTokenResource;
use App\Support\ApiTokenIssuer;
use App\Support\AuthenticationLifecycleManager;

class LoginController extends Controller
{
    /**
     * Autentica por e-mail/senha e devolve um Bearer token novo.
     */
    public function __invoke(
        LoginRequest $request,
        ApiTokenIssuer $tokens,
        AuthenticationLifecycleManager $authentication,
    ): AuthTokenResource {
        $user = $request->authenticate();

        $authentication->afterLogin($user);

        return new AuthTokenResource(
            $tokens->issue($user, $request->deviceName()),
            $user,
        );
    }
}
