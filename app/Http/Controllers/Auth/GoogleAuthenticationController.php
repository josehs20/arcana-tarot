<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\AttendantAvailabilityManager;
use App\Support\AuthenticationSettings;
use App\Support\FirstAccessBonus;
use App\Support\GoogleAccountException;
use App\Support\GoogleAccountManager;
use App\Support\SiteContent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Login e cadastro com Google (OAuth 2.0 via Socialite).
 *
 * O botão só aparece quando `AuthenticationSettings::googleEnabled()` é
 * verdadeiro. No retorno, o e-mail do Google precisa estar verificado e
 * conflitos com contas locais são tratados explicitamente (vínculo único por
 * `google_id`). O bônus de primeiro acesso é concedido apenas para contas
 * criadas nesta autenticação.
 */
class GoogleAuthenticationController extends Controller
{
    public function redirect(): SymfonyRedirectResponse|RedirectResponse
    {
        if (! AuthenticationSettings::googleEnabled()) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'O login com Google não está disponível neste momento.']);
        }

        return Socialite::driver('google')
            ->scopes((array) config('authentication.google.scopes', ['openid', 'profile', 'email']))
            ->redirect();
    }

    public function callback(
        Request $request,
        GoogleAccountManager $accounts,
        FirstAccessBonus $bonus,
        AttendantAvailabilityManager $availability,
    ): RedirectResponse {
        if (! AuthenticationSettings::googleEnabled()) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'O login com Google não está disponível neste momento.']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $exception) {
            // Estado OAuth expirado/adulterado: o cliente recomeça o fluxo.
            Log::warning('Login com Google recusado por estado inválido.', ['exception' => $exception]);

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Sua sessão de login com o Google expirou. Tente novamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Não foi possível concluir o login com o Google agora. Use e-mail e senha ou tente novamente.']);
        }

        try {
            $user = $accounts->resolve(
                (string) $googleUser->getId(),
                (string) $googleUser->getEmail(),
                (string) ($googleUser->getName() ?? ''),
                GoogleAccountManager::emailIsVerified((array) $googleUser->getRaw()),
            );
        } catch (GoogleAccountException $exception) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => $exception->getMessage()]);
        }

        if ($user->wasRecentlyCreated) {
            event(new Registered($user));

            try {
                $bonus->grantFor($user);
            } catch (Throwable $exception) {
                // O bônus é um benefício: nunca pode impedir o login.
                report($exception);
            }
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        if ($user->role === UserRole::Attendant) {
            try {
                $availability->signIn($user);
            } catch (Throwable $exception) {
                // O status é um efeito colateral do login: nunca bloqueia.
                report($exception);
            }
        }

        return redirect()
            ->route($user->dashboardRouteName())
            ->with('success', $user->wasRecentlyCreated
                ? 'Conta criada com o Google. Boas-vindas ao '.SiteContent::currentName().'!'
                : 'Login com Google realizado.');
    }
}
