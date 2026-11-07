<?php

namespace App\Support;

use App\Enums\PhoneVerificationStatus;
use App\Enums\SmsVerificationResult;
use App\Models\PhoneVerification;
use App\Support\Sms\Contracts\SmsVerificationProvider;
use App\Support\Sms\SmsVerificationException;

/**
 * Conduz o cadastro com confirmação de celular por SMS.
 *
 * O usuário só é criado depois do código aprovado: até lá os dados do cadastro
 * (nome, e-mail e hash da senha) ficam em `phone_verifications.payload`. A
 * verificação pendente é referenciada pela sessão, nunca pelo navegador, e o
 * limite de tentativas/validade é aplicado aqui antes de consultar o provedor.
 */
final class PhoneVerificationService
{
    public function __construct(
        private readonly SmsVerificationProvider $provider,
    ) {}

    /**
     * Cria a verificação pendente e envia o código.
     *
     * @param  array{name: string, email: string, birth_date?: string|null, gender?: string|null, password_hash: string}  $payload
     *
     * @throws SmsVerificationException quando o provedor não consegue enviar
     */
    public function start(array $payload, string $phone, ?string $ipAddress = null): PhoneVerification
    {
        $verification = PhoneVerification::query()->create([
            'phone' => $phone,
            'status' => PhoneVerificationStatus::Pending,
            'attempts' => 0,
            'payload' => $payload,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'ip_address' => $ipAddress,
        ]);

        try {
            $this->provider->send(PhoneNumber::toE164($phone));
        } catch (SmsVerificationException $exception) {
            $verification->forceFill(['status' => PhoneVerificationStatus::Failed])->save();

            throw $exception;
        }

        $this->remember($verification);

        return $verification;
    }

    /**
     * Verificação apontada pela sessão atual, quando ainda está pendente.
     */
    public function pending(): ?PhoneVerification
    {
        $id = session(config('authentication.sms.session_key'));

        if (! is_scalar($id) || (int) $id < 1) {
            return null;
        }

        $verification = PhoneVerification::query()->find((int) $id);

        if ($verification === null || ! $verification->isPending()) {
            return null;
        }

        return $verification;
    }

    /**
     * Confere o código junto ao provedor, registrando tentativa e situação.
     *
     * @throws SmsVerificationException quando o provedor está indisponível
     */
    public function verify(PhoneVerification $verification, string $code): SmsVerificationResult
    {
        if (! $verification->isPending()) {
            return SmsVerificationResult::Expired;
        }

        if ($verification->isExpired()) {
            $verification->forceFill(['status' => PhoneVerificationStatus::Expired])->save();

            return SmsVerificationResult::Expired;
        }

        if ($verification->attempts >= $this->maxAttempts()) {
            $verification->forceFill(['status' => PhoneVerificationStatus::Failed])->save();

            return SmsVerificationResult::TooManyAttempts;
        }

        $result = $this->provider->check(PhoneNumber::toE164($verification->phone), $code);
        $attempts = $verification->attempts + 1;

        if ($result === SmsVerificationResult::Approved) {
            $verification->forceFill([
                'status' => PhoneVerificationStatus::Approved,
                'attempts' => $attempts,
                'consumed_at' => now(),
            ])->save();

            return SmsVerificationResult::Approved;
        }

        $exhausted = $attempts >= $this->maxAttempts();

        $verification->forceFill([
            'attempts' => $attempts,
            'status' => match (true) {
                $result === SmsVerificationResult::Expired => PhoneVerificationStatus::Expired,
                $exhausted, $result === SmsVerificationResult::TooManyAttempts => PhoneVerificationStatus::Failed,
                default => PhoneVerificationStatus::Pending,
            },
        ])->save();

        return $exhausted ? SmsVerificationResult::TooManyAttempts : $result;
    }

    /**
     * Reenvia o código da verificação pendente, renovando validade e tentativas.
     *
     * @throws SmsVerificationException quando o provedor não consegue enviar
     */
    public function resend(PhoneVerification $verification): void
    {
        $this->provider->send(PhoneNumber::toE164($verification->phone));

        $verification->forceFill([
            'status' => PhoneVerificationStatus::Pending,
            'attempts' => 0,
            'consumed_at' => null,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ])->save();
    }

    /**
     * Segundos restantes até permitir um novo envio (0 quando liberado).
     */
    public function secondsUntilResend(PhoneVerification $verification): int
    {
        $availableAt = $verification->updated_at->getTimestamp() + $this->resendCooldownSeconds();

        return max(0, $availableAt - now()->getTimestamp());
    }

    public function expiresInMinutes(PhoneVerification $verification): int
    {
        $seconds = $verification->expires_at->getTimestamp() - now()->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }

    public function remainingAttempts(PhoneVerification $verification): int
    {
        return max(0, $this->maxAttempts() - $verification->attempts);
    }

    public function remember(PhoneVerification $verification): void
    {
        session([config('authentication.sms.session_key') => $verification->getKey()]);
    }

    public function forget(): void
    {
        session()->forget(config('authentication.sms.session_key'));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('authentication.sms.ttl_minutes', 10));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('authentication.sms.max_attempts', 5));
    }

    private function resendCooldownSeconds(): int
    {
        return max(0, (int) config('authentication.sms.resend_cooldown_seconds', 60));
    }
}
