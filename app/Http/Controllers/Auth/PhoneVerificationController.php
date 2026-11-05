<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SmsVerificationResult;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPhoneVerificationRequest;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Support\AuthenticationSettings;
use App\Support\CreditWalletManager;
use App\Support\FirstAccessBonus;
use App\Support\PhoneVerificationService;
use App\Support\Sms\SmsVerificationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Etapa de confirmação do celular no cadastro por e-mail.
 *
 * A conta só existe depois do código aprovado. Enquanto isso, os dados do
 * cadastro ficam na verificação referenciada pela sessão. Erros do provedor
 * são registrados no log e devolvem mensagem genérica ao cliente.
 */
class PhoneVerificationController extends Controller
{
    public function show(PhoneVerificationService $service): View|RedirectResponse
    {
        $verification = $service->pending();

        if ($verification === null) {
            return $this->restart('Confirme seu celular para concluir o cadastro. Informe seus dados novamente.');
        }

        return view('auth.verify-phone', [
            'phone' => $verification->maskedPhone(),
            'expired' => $verification->isExpired(),
            'expiresInMinutes' => $service->expiresInMinutes($verification),
            'secondsUntilResend' => $service->secondsUntilResend($verification),
            'remainingAttempts' => $service->remainingAttempts($verification),
            'codeLength' => AuthenticationSettings::smsCodeLength(),
        ]);
    }

    public function verify(
        ConfirmPhoneVerificationRequest $request,
        PhoneVerificationService $service,
        CreditWalletManager $wallets,
        FirstAccessBonus $bonus,
    ): RedirectResponse {
        $verification = $service->pending();

        if ($verification === null) {
            return $this->restart('Sua confirmação expirou. Informe seus dados novamente para receber um novo código.');
        }

        try {
            $result = $service->verify($verification, (string) $request->validated('code'));
        } catch (SmsVerificationException $exception) {
            report($exception);

            return redirect()
                ->route('register.verify')
                ->withErrors(['code' => 'Não foi possível conferir o código agora. Tente novamente em instantes.']);
        }

        return match ($result) {
            SmsVerificationResult::Approved => $this->complete($request, $verification, $service, $wallets, $bonus),
            SmsVerificationResult::Invalid => redirect()
                ->route('register.verify')
                ->withErrors(['code' => $this->invalidCodeMessage($service, $verification)]),
            SmsVerificationResult::Expired => redirect()
                ->route('register.verify')
                ->withErrors(['code' => 'O código expirou. Solicite um novo código abaixo.']),
            SmsVerificationResult::TooManyAttempts => $this->restart(
                'Muitas tentativas inválidas. Informe seus dados novamente para receber um novo código.',
            ),
        };
    }

    public function resend(PhoneVerificationService $service): RedirectResponse
    {
        $verification = $service->pending();

        if ($verification === null) {
            return $this->restart('Sua confirmação expirou. Informe seus dados novamente para receber um novo código.');
        }

        $seconds = $service->secondsUntilResend($verification);

        if ($seconds > 0) {
            return redirect()
                ->route('register.verify')
                ->withErrors(['code' => sprintf('Aguarde %d segundo(s) para reenviar o código.', $seconds)]);
        }

        try {
            $service->resend($verification);
        } catch (SmsVerificationException $exception) {
            report($exception);

            return redirect()
                ->route('register.verify')
                ->withErrors(['code' => 'Não foi possível enviar um novo código agora. Tente novamente em instantes.']);
        }

        return redirect()
            ->route('register.verify')
            ->with('status', 'Enviamos um novo código por SMS para '.$verification->maskedPhone().'.');
    }

    /**
     * Cria a conta confirmada, concede o bônus e autentica o cliente.
     */
    private function complete(
        Request $request,
        PhoneVerification $verification,
        PhoneVerificationService $service,
        CreditWalletManager $wallets,
        FirstAccessBonus $bonus,
    ): RedirectResponse {
        $payload = $verification->registrationPayload();

        if ($payload['email'] === '' || $payload['name'] === '' || $payload['password_hash'] === '') {
            $service->forget();

            return $this->restart('Não encontramos os dados do seu cadastro. Informe-os novamente.');
        }

        if (User::query()->where('email', $payload['email'])->exists()) {
            $service->forget();

            return $this->restart('Já existe uma conta com este e-mail. Entre com sua senha.');
        }

        $user = DB::transaction(function () use ($payload, $verification, $wallets): User {
            $user = User::create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'phone' => $verification->phone,
                'phone_verified_at' => now(),
                'birth_date' => ($payload['birth_date'] ?? '') !== '' ? $payload['birth_date'] : null,
                'password' => $payload['password_hash'],
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
            // O bônus é um benefício: nunca pode impedir a conclusão do cadastro.
            report($exception);
        }

        $service->forget();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()
            ->route($user->dashboardRouteName())
            ->with('success', 'Celular confirmado. Bem-vindo(a) à Arcana Tarot!');
    }

    private function invalidCodeMessage(PhoneVerificationService $service, PhoneVerification $verification): string
    {
        $remaining = $service->remainingAttempts($verification);

        if ($remaining < 1) {
            return 'Código incorreto. O limite de tentativas foi atingido; solicite um novo código.';
        }

        return sprintf(
            'Código incorreto. Restam %d tentativa(s) antes de precisar de um novo código.',
            $remaining,
        );
    }

    private function restart(string $message): RedirectResponse
    {
        return redirect()->route('register')->withErrors(['phone' => $message]);
    }
}
