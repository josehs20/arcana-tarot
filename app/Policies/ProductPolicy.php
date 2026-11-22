<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Somente administradores ativos gerenciam a vitrine de produtos.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function changeStatus(User $user, Product $product): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
