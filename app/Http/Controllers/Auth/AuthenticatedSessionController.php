<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\AttendantAvailabilityManager;
use App\Support\ConsultationLifecycleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        LoginRequest $request,
        AttendantAvailabilityManager $availability,
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user !== null && $user->role === UserRole::Attendant) {
            $this->bringAttendantOnline($user, $availability);
        }

        return redirect()->route($user->dashboardRouteName());
    }

    /**
     * Entrar na conta deixa o atendente online por padrão.
     *
     * A disponibilidade é um efeito colateral do login e nunca pode impedir a
     * autenticação: falhas são reportadas e o usuário segue para o painel.
     */
    private function bringAttendantOnline(User $user, AttendantAvailabilityManager $availability): void
    {
        try {
            $availability->signIn($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function destroy(
        Request $request,
        AttendantAvailabilityManager $availability,
        ConsultationLifecycleManager $lifecycle,
    ): RedirectResponse {
        $user = $request->user();

        if ($user !== null) {
            try {
                $lifecycle->endActiveForLogout($user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($user !== null && $user->role === UserRole::Attendant) {
            try {
                $availability->signOut($user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
