<?php

namespace App\Support;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;

/**
 * Localiza o atendimento ativo do usuário autenticado.
 *
 * É a única fonte usada pelo aviso de navegação, pelo botão de compra de
 * créditos e pelo retorno ao chat depois do pagamento. A consulta é escolhida
 * no servidor, nunca a partir de um ID enviado pelo navegador.
 */
final class ActiveConsultationResolver
{
    public function forUser(?User $user): ?Consultation
    {
        if ($user === null || ! $user->is_active || $user->isAdmin()) {
            return null;
        }

        return Consultation::query()
            ->with(['client', 'attendant'])
            ->where('status', ConsultationStatus::Active->value)
            ->where(function ($query) use ($user): void {
                $query->where('client_id', $user->getKey())
                    ->orWhere('attendant_id', $user->getKey());
            })
            ->latest('id')
            ->first();
    }
}
