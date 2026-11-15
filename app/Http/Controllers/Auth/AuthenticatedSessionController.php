<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\AuthenticationLifecycleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        LoginRequest $request,
        AuthenticationLifecycleManager $authentication,
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        /*
         * Conta criada mas com o e-mail ainda não confirmado: o painel está
         * bloqueado pelo middleware `verified`, então o login termina na tela
         * de aviso (onde o link pode ser reenviado).
         */
        if ($user !== null && ! $user->hasVerifiedEmail()) {
            return redirect()
                ->route('verification.notice')
                ->with('status', 'Confirme seu e-mail para liberar o acesso ao painel.');
        }

        if ($user !== null) {
            $authentication->afterLogin($user);
        }

        return redirect()->route($user->dashboardRouteName());
    }

    public function destroy(
        Request $request,
        AuthenticationLifecycleManager $authentication,
    ): RedirectResponse {
        $user = $request->user();

        if ($user !== null) {
            $authentication->afterLogout($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
