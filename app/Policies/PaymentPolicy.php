<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Autoriza a consulta do histórico de recargas do próprio cliente.
     * O controller sempre filtra por `user_id` do usuário autenticado.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->is_active && $payment->user_id === $user->getKey();
    }

    /**
     * Listagem financeira global: exclusiva de administradores ativos.
     * O cliente continua vendo apenas as próprias recargas por viewAny/view.
     */
    public function viewAnyAsAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }
}
