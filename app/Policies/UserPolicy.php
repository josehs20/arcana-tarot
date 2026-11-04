<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    /**
     * Gestão de status pelo painel: admins não podem ser desativados e
     * ninguém pode desativar a própria conta (evita perda de acesso).
     */
    public function manageStatus(User $user, User $target): bool
    {
        return $this->isActiveAdmin($user)
            && $user->getKey() !== $target->getKey()
            && ! $target->isAdmin();
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
