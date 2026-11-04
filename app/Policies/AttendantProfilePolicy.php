<?php

namespace App\Policies;

use App\Models\AttendantProfile;
use App\Models\User;

class AttendantProfilePolicy
{
    public function view(User $user, AttendantProfile $profile): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isAdmin() || $profile->user_id === $user->getKey();
    }

    public function updateAvailability(User $user, AttendantProfile $profile): bool
    {
        return $user->is_active
            && $user->isAttendant()
            && $profile->user_id === $user->getKey();
    }

    public function updateCommission(User $user, AttendantProfile $profile): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function updateModalities(User $user, AttendantProfile $profile): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function updateRequestSound(User $user, AttendantProfile $profile): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
