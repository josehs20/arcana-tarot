<?php

namespace App\Support;

use App\Models\AttendantProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta pública do diretório de atendentes.
 *
 * A lista de colunas é fechada de propósito: e-mail, comissão e dados
 * financeiros nunca saem por aqui. Web e API compartilham a mesma seleção e a
 * mesma ordenação (online, ocupado, offline; depois nome público).
 */
final class AttendantDirectoryQuery
{
    /**
     * @return Builder<AttendantProfile>
     */
    public function query(): Builder
    {
        return AttendantProfile::query()
            ->select(['id', 'user_id', 'display_name', 'bio', 'specialties', 'offered_modalities', 'photo_path', 'availability'])
            ->with('modalityPrices')
            ->withExists('activeConsultation')
            ->published()
            ->orderForDirectory();
    }

    public function findPublishedOrFail(int $attendantId): AttendantProfile
    {
        return $this->query()->findOrFail($attendantId);
    }
}
