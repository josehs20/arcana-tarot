<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de contas de cliente.
 *
 * A conta e a carteira nascem na mesma transação; qualquer falha depois disso
 * deixa o banco consistente. Efeitos de sessão (login, evento `Registered`,
 * envio do link de confirmação) continuam no controller, que decide como
 * responder no canal usado — web ou API.
 */
final class ClientAccountManager
{
    public function __construct(
        private readonly CreditWalletManager $wallets,
    ) {}

    /**
     * @param  array{name: string, email: string, phone: string, birth_date: string, gender: string, password: string}  $attributes
     */
    public function register(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'],
                'birth_date' => $attributes['birth_date'],
                'gender' => $attributes['gender'],
                'password' => $attributes['password'],
                'role' => UserRole::Client->value,
                'is_active' => true,
            ]);

            $this->wallets->walletFor($user);

            return $user;
        });
    }
}
