<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\GoogleLoginRequest;
use App\Http\Resources\AuthTokenResource;
use App\Support\ApiTokenIssuer;
use App\Support\AuthenticationLifecycleManager;
use App\Support\AuthenticationSettings;
use App\Support\FirstAccessBonus;
use App\Support\GoogleAccountException;
use App\Support\GoogleAccountManager;
use App\Support\GoogleIdTokenVerifier;
use Illuminate\Auth\Events\Registered;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Login/cadastro com Google para o app móvel.
 *
 * Recebe apenas o ID token do SDK oficial. A validação de assinatura,
 * emissor, audiência e `email_verified` fica no backend; o vínculo seguro por
 * `google_id` é o mesmo do fluxo web (GoogleAccountManager).
 */
class GoogleController extends Controller
{
    public function __invoke(
        GoogleLoginRequest $request,
        GoogleIdTokenVerifier $verifier,
        GoogleAccountManager $accounts,
        FirstAccessBonus $bonus,
        ApiTokenIssuer $tokens,
        AuthenticationLifecycleManager $authentication,
    ): AuthTokenResource {
        if (! AuthenticationSettings::googleMobileEnabled()) {
            abort(503, 'O login com Google não está disponível no aplicativo.');
        }

        try {
            $identity = $verifier->verify($request->idToken());

            $user = $accounts->resolve(
                $identity['google_id'],
                $identity['email'],
                $identity['name'],
                $identity['email_verified'],
            );
        } catch (GoogleAccountException $exception) {
            if (in_array($exception->status(), [403, 409, 503], true)) {
                abort($exception->status(), $exception->getMessage());
            }

            throw ValidationException::withMessages([
                'id_token' => $exception->getMessage(),
            ]);
        }

        if ($user->wasRecentlyCreated) {
            try {
                event(new Registered($user));
            } catch (Throwable $exception) {
                report($exception);
            }

            try {
                $bonus->grantFor($user);
            } catch (Throwable $exception) {
                // O bônus é um benefício: nunca pode impedir o login.
                report($exception);
            }
        }

        $authentication->afterLogin($user);

        return new AuthTokenResource(
            $tokens->issue($user, $request->deviceName()),
            $user,
        );
    }
}
