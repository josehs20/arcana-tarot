<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

/**
 * Vendas de produtos são somente leitura no painel.
 *
 * O status nasce exclusivamente da confirmação server-side do provedor; não há
 * tela administrativa capaz de marcar uma compra como paga manualmente.
 */
class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->is_active && ($user->isAdmin() || $purchase->user_id === $user->getKey());
    }
}
