<?php

namespace App\Policies;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;

/**
 * Autorização do catálogo administrativo e das imagens privadas.
 *
 * Administradores ativos gerenciam o catálogo e podem pré-visualizar qualquer
 * carta. Clientes/atendentes só acessam imagens de cartas ativas de baralhos
 * ativos enquanto participarem de uma consulta ativa.
 */
class TarotCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, TarotDeck|TarotCard $model): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function changeStatus(User $user, TarotDeck|TarotCard $model): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function viewImage(User $user, TarotCard $card): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (! $card->is_active || ! $card->deck?->is_active) {
            return false;
        }

        return Consultation::query()
            ->where('status', ConsultationStatus::Active->value)
            ->where(function ($query) use ($user): void {
                $query->where('client_id', $user->getKey())
                    ->orWhere('attendant_id', $user->getKey());
            })
            ->exists();
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
