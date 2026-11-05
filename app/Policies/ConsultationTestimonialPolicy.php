<?php

namespace App\Policies;

use App\Models\ConsultationTestimonial;
use App\Models\User;

class ConsultationTestimonialPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    /**
     * O cliente acompanha o próprio depoimento; a administração vê todos para
     * moderar. O atendente não tem acesso ao painel de depoimentos: o retorno
     * chega apenas pela página pública do perfil.
     */
    public function view(User $user, ConsultationTestimonial $testimonial): bool
    {
        return $this->isActiveAdmin($user)
            || ($user->is_active && $testimonial->client_id === $user->getKey());
    }

    public function moderate(User $user, ConsultationTestimonial $testimonial): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
