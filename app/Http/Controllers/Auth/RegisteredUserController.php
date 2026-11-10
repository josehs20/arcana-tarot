<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\CreditWalletManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public function store(RegisterRequest $request, CreditWalletManager $wallets): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $wallets): User {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'birth_date' => $request->validated('birth_date'),
                'gender' => $request->validated('gender'),
                'password' => $request->validated('password'),
                'role' => UserRole::Client->value,
                'is_active' => true,
            ]);

            $wallets->walletFor($user);

            return $user;
        });

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
