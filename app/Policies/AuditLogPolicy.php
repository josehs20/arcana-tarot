<?php

namespace App\Policies;

use App\Models\User;

/**
 * A trilha de auditoria é somente leitura e exclusiva de administradores
 * ativos. Não há criação, edição ou exclusão pelo painel.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
