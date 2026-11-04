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
                'password' => $request->validated('password'),
                'role' => UserRole::Client->value,
                'is_active' => true,
            ]);

            $wallets->walletFor($user);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('client.dashboard');
    }
}
