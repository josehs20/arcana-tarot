<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;

/**
 * Autorização das campanhas de créditos.
 *
 * Somente administradores ativos gerenciam promoções; a home pública não usa a
 * policy (as promoções ativas são conteúdo público).
 */
class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function changeStatus(User $user, Promotion $promotion): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
