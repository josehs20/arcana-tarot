<?php

namespace App\Support;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consultas em aberto do usuário autenticado.
 *
 * Alimenta o feed do painel (consulta aberta e fila de solicitações do
 * atendente) e os mesmos dados que a API vai expor. É somente leitura: não
 * grava atividade, presença, mensagens lidas nem cobrança.
 */
final class ConsultationInbox
{
    public function openFor(User $user): ?Consultation
    {
        return Consultation::query()
            ->with(['client', 'attendant.attendantProfile'])
            ->where(function ($query) use ($user): void {
                $query->where('client_id', $user->getKey())
                    ->orWhere('attendant_id', $user->getKey());
            })
            ->whereIn('status', ConsultationStatus::openValues())
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, Consultation>
     */
    public function pendingForAttendant(User $attendant): Collection
    {
        return $attendant->attendantConsultations()
            ->with(['client', 'attendant.attendantProfile'])
            ->where('status', ConsultationStatus::Requested->value)
            ->oldest()
            ->get()
            ->values();
    }
}
