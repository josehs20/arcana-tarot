<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\FirstAccessBonus;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Confirmação de e-mail do cadastro.
 *
 * O cadastro cria a conta na hora e já autentica o cliente; o acesso aos
 * painéis fica bloqueado pelo middleware `verified` até o link assinado ser
 * aberto. O bônus de primeiro acesso é concedido aqui — e não no cadastro —
 * porque é neste momento que a conta passa a valer.
 */
class EmailVerificationController extends Controller
{
    /**
     * Aviso "Confirme seu e-mail", com o botão de reenvio.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route($user->dashboardRouteName());
        }

        return view('auth.verify-email', [
            'email' => $user->email,
            'expiresInMinutes' => (int) config('auth.verification.expire', 60),
        ]);
    }

    /**
     * Abre o link assinado, marca o e-mail como confirmado e libera o painel.
     */
    public function verify(EmailVerificationRequest $request, FirstAccessBonus $bonus): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));

            try {
                $bonus->grantFor($user);
            } catch (Throwable $exception) {
                // O bônus é um extra: uma falha ao creditar não desfaz a
                // confirmação, que já está gravada no banco.
                report($exception);
            }
        }

        return redirect()
            ->intended(route($user->dashboardRouteName()))
            ->with('success', 'E-mail confirmado! Sua conta está liberada.');
    }

    /**
     * Reenvia o link. O limite por sessão fica nas rotas (`throttle:6,1`).
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route($user->dashboardRouteName());
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            // SMTP fora do ar não pode virar erro 500: a conta já existe e o
            // cliente pode tentar de novo pelo mesmo botão.
            report($exception);

            return back()->with(
                'warning',
                'Não conseguimos enviar o e-mail agora. Tente novamente em alguns minutos.',
            );
        }

        return back()->with('status', 'Enviamos um novo link de confirmação para '.$user->email.'.');
    }
}
