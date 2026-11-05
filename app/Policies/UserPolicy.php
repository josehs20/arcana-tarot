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
     * Cadastro administrativo de atendentes.
     */
    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    /**
     * Edição dos dados de uma conta.
     *
     * Contas administrativas ficam fora do fluxo: a tela não troca papéis e não
     * permite que um administrador altere o acesso de outro.
     */
    public function update(User $user, User $target): bool
    {
        return $this->isActiveAdmin($user) && ! $target->isAdmin();
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
