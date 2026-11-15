<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login por e-mail e senha na API.
 *
 * Não usa `Auth::attempt` nem sessão: valida a senha, devolve o usuário e o
 * controller emite o Bearer token. Mantém o mesmo limite de tentativas por
 * e-mail/IP do login web, além do `throttle:api-auth` da rota.
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_scalar($email)) {
            $this->merge(['email' => Str::lower(trim((string) $email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe um e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'Informe sua senha.',
        ];
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()
            ->where('email', (string) $this->input('email'))
            ->first();

        if (
            $user === null
            || ! $user->is_active
            || ! Hash::check((string) $this->input('password'), $user->password)
        ) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    public function deviceName(): ?string
    {
        $name = $this->input('device_name');

        return is_string($name) ? $name : null;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return 'api-login:'.Str::transliterate(
            Str::lower((string) $this->input('email')).'|'.$this->ip(),
        );
    }
}
