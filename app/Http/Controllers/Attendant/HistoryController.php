<?php

namespace App\Http\Controllers\Attendant;

use App\Enums\CommissionEntryType;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\User;
use App\Support\CommissionSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Histórico do atendente limitado aos próprios atendimentos e lançamentos.
 *
 * A taxa, a base e o valor vêm do snapshot da consulta e do razão imutável.
 * Não existe rota para editar ganhos ou taxas históricas.
 */
class HistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('access-attendant-panel');
        Gate::authorize('viewAttendantPanel', Consultation::class);

        /** @var User $user */
        $user = $request->user();
        $profile = $user->attendantProfile;

        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'modality' => ['nullable', Rule::enum(ConsultationModality::class)],
            'type' => ['nullable', Rule::enum(CommissionEntryType::class)],
        ]);

        $consultations = $user->attendantConsultations()
            ->with(['client', 'commissionEntries'])
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['modality']),
                fn ($query) => $query->where('modality', $filters['modality']),
            )
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $commissionEntries = $user->commissionEntries()
            ->with('consultation')
            ->when(
                isset($filters['type']),
                fn ($query) => $query->where('type', $filters['type']),
            )
            ->latest('id')
            ->paginate(20, ['*'], 'commissions_page')
            ->withQueryString();

        return view('attendant.history', [
            'profile' => $profile,
            'consultations' => $consultations,
            'commissionEntries' => $commissionEntries,
            'summary' => CommissionSummary::forAttendant($user),
            'filters' => $filters,
            'statuses' => ConsultationStatus::cases(),
            'modalities' => ConsultationModality::cases(),
            'commissionTypes' => CommissionEntryType::cases(),
        ]);
    }
}
