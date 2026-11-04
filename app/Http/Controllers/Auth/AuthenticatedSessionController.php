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

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->route($this->redirectFor($request->user()));
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

    private function redirectFor(User $user): string
    {
        return match ($user->role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Attendant => 'attendant.dashboard',
            UserRole::Client => 'client.dashboard',
        };
    }
}
