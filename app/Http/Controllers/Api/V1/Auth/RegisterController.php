<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\AuthTokenResource;
use App\Support\ApiTokenIssuer;
use App\Support\AuthenticationLifecycleManager;
use App\Support\ClientAccountManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Throwable;

class RegisterController extends Controller
{
    /**
     * Cadastro de cliente pela API.
     *
     * A conta nasce sem e-mail confirmado; o token é emitido para o app poder
     * consultar `/auth/me` e pedir o reenvio do link. O bônus de primeiro
     * acesso continua sendo concedido somente quando o e-mail é confirmado
     * (ou imediatamente no Google, cujo e-mail já vem verificado).
     */
    public function __invoke(
        RegisterRequest $request,
        ClientAccountManager $accounts,
        ApiTokenIssuer $tokens,
        AuthenticationLifecycleManager $authentication,
    ): JsonResponse {
        $user = $accounts->register(Arr::only($request->validated(), [
            'name',
            'email',
            'phone',
            'birth_date',
            'gender',
            'password',
        ]));

        $authentication->afterLogin($user);

        try {
            // O listener do framework envia o link de confirmação
            // (App\Notifications\Auth\VerifyEmailNotification).
            event(new Registered($user));
        } catch (Throwable $exception) {
            // A conta já foi criada; um SMTP fora do ar não pode desfazer o
            // cadastro. O app pode pedir o reenvio em
            // POST /auth/email/verification-notification.
            report($exception);
        }

        $deviceName = $request->input('device_name');

        return (new AuthTokenResource(
            $tokens->issue($user, is_string($deviceName) ? $deviceName : null),
            $user,
        ))->response()->setStatusCode(201);
    }
}
