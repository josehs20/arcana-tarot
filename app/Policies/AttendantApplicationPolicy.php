<?php

namespace App\Policies;

use App\Models\AttendantApplication;
use App\Models\User;

class AttendantApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, AttendantApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function review(User $user, AttendantApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
