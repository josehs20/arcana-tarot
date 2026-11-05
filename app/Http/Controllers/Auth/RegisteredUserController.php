<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\AuthenticationSettings;
use App\Support\CreditWalletManager;
use App\Support\FirstAccessBonus;
use App\Support\PhoneNumber;
use App\Support\PhoneVerificationService;
use App\Support\Sms\SmsVerificationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

/**
 * Cadastro por e-mail e senha.
 *
 * Com SMS_VERIFICATION_ENABLED=true o cadastro passa por duas etapas: os dados
 * ficam guardados em `phone_verifications` e a conta só é criada depois do
 * código confirmado (PhoneVerificationController). Sem SMS, o fluxo continua
 * em uma única etapa, como antes.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(
        RegisterRequest $request,
        CreditWalletManager $wallets,
        PhoneVerificationService $sms,
        FirstAccessBonus $bonus,
    ): RedirectResponse {
        if (AuthenticationSettings::smsEnabled()) {
            return $this->startPhoneVerification($request, $sms);
        }

        return $this->registerDirectly($request, $wallets, $bonus);
    }

    /**
     * Primeira etapa do cadastro com SMS: guarda os dados e envia o código.
     */
    private function startPhoneVerification(RegisterRequest $request, PhoneVerificationService $sms): RedirectResponse
    {
        $phone = (string) $request->validated('phone');

        try {
            $sms->start(
                [
                    'name' => (string) $request->validated('name'),
                    'email' => (string) $request->validated('email'),
                    'birth_date' => (string) $request->validated('birth_date'),
                    'password_hash' => Hash::make((string) $request->validated('password')),
                ],
                $phone,
                $request->ip(),
            );
        } catch (SmsVerificationException $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['phone' => 'Não foi possível enviar o código por SMS agora. Confira o número e tente novamente.']);
        }

        return redirect()
            ->route('register.verify')
            ->with('status', 'Enviamos um código por SMS para '.PhoneNumber::format($phone).'.');
    }

    /**
     * Cadastro em uma etapa (SMS desativado na instalação).
     */
    private function registerDirectly(
        RegisterRequest $request,
        CreditWalletManager $wallets,
        FirstAccessBonus $bonus,
    ): RedirectResponse {
        $user = DB::transaction(function () use ($request, $wallets): User {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'birth_date' => $request->validated('birth_date'),
                'password' => $request->validated('password'),
                'role' => UserRole::Client->value,
                'is_active' => true,
            ]);

            $wallets->walletFor($user);

            return $user;
        });

        event(new Registered($user));

        try {
            $bonus->grantFor($user);
        } catch (Throwable $exception) {
            // O bônus é um benefício: nunca pode impedir o cadastro.
            report($exception);
        }

        Auth::login($user);

        return redirect()->route($user->dashboardRouteName());
    }
}
