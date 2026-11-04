<?php

namespace App\Policies;

use App\Models\CommissionLedgerEntry;
use App\Models\User;

/**
 * Comissões são dados internos. O atendente consulta os próprios valores pelo
 * dashboard; o razão administrativo e estornos são exclusivos de admins.
 */
class CommissionLedgerEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, CommissionLedgerEntry $entry): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function reverse(User $user, CommissionLedgerEntry $entry): bool
    {
        return $this->isActiveAdmin($user) && $entry->canBeReversed();
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
