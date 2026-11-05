<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolve a identidade Google em uma conta local.
 *
 * Regras:
 * - `google_id` é o `sub` do Google e identifica a conta mesmo se o e-mail
 *   mudar dentro do Google;
 * - um e-mail já cadastrado é vinculado ao Google apenas se ainda não tiver
 *   outro `google_id`, preservando senha, telefone e histórico;
 * - contas desativadas continuam bloqueadas;
 * - contas novas nascem como cliente ativo, com carteira vazia e senha
 *   aleatória (o cliente pode definir uma pelo "Esqueceu a senha?").
 */
final class GoogleAccountManager
{
    public function __construct(
        private readonly CreditWalletManager $wallets,
    ) {}

    public function resolve(string $googleId, string $email, string $name, bool $emailVerified): User
    {
        $googleId = trim($googleId);
        $email = Str::lower(trim($email));
        $name = Str::squish($name);

        if ($googleId === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw GoogleAccountException::missingIdentity();
        }

        if (! $emailVerified) {
            throw GoogleAccountException::unverifiedEmail();
        }

        $linked = User::query()->where('google_id', $googleId)->first();

        if ($linked !== null) {
            $this->ensureActive($linked);

            return $linked;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->ensureActive($existing);

            if ($existing->google_id !== null) {
                throw GoogleAccountException::alreadyLinked();
            }

            $existing->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return $existing;
        }

        return DB::transaction(function () use ($googleId, $email, $name): User {
            $user = User::create([
                'name' => $name !== '' ? $name : Str::headline(Str::before($email, '@')),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => Str::random(64),
                'role' => UserRole::Client->value,
                'is_active' => true,
            ]);

            $this->wallets->walletFor($user);

            return $user;
        });
    }

    /**
     * A claim `email_verified` acompanha o userinfo do Google; quando ausente,
     * o e-mail é aceito (contas Google são verificadas na origem).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function emailIsVerified(array $raw): bool
    {
        return ! array_key_exists('email_verified', $raw) || (bool) $raw['email_verified'];
    }

    private function ensureActive(User $user): void
    {
        if (! $user->is_active) {
            throw GoogleAccountException::inactive();
        }
    }
}
