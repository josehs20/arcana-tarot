<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Support\ClientAccountManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

/**
 * Cadastro por e-mail e senha.
 *
 * O fluxo é de uma etapa só: a conta é criada, a carteira é aberta e o cliente
 * já entra. O acesso aos painéis, porém, fica bloqueado pelo middleware
 * `verified` até o link enviado por e-mail ser confirmado
 * (EmailVerificationController) — e é lá que o bônus de primeiro acesso é
 * concedido.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, ClientAccountManager $accounts): RedirectResponse
    {
        $user = $accounts->register(Arr::only($request->validated(), [
            'name',
            'email',
            'phone',
            'birth_date',
            'gender',
            'password',
        ]));

        Auth::login($user);

        try {
            // O listener do framework envia o link de confirmação
            // (App\Notifications\Auth\VerifyEmailNotification).
            event(new Registered($user));
        } catch (Throwable $exception) {
            // A conta já existe: um SMTP fora do ar não pode derrubar o
            // cadastro. O cliente reenvia o link pela tela de aviso.
            report($exception);

            return redirect()
                ->route('verification.notice')
                ->with('warning', 'Sua conta foi criada, mas não conseguimos enviar o e-mail de confirmação agora. Use "Reenviar e-mail" para tentar de novo.');
        }

        return redirect()
            ->route('verification.notice')
            ->with('status', 'Enviamos um link de confirmação para '.$user->email.'.');
    }
}
