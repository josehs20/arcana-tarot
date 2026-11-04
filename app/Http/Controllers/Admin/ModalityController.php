<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Http\Controllers\Controller;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationModalityCatalog;
use App\Support\ConsultationPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Modalidades realmente oferecidas por cada atendente.
 *
 * Texto e a modalidade base e permanece sempre habilitada. Audio e video
 * dependem tambem da infraestrutura global, portanto nao podem ser atribuidos
 * quando o recurso esta desligado no servidor.
 */
class ModalityController extends Controller
{
    public function index(
        Request $request,
        ConsultationModalityCatalog $catalog,
        ConsultationPricing $pricing,
    ): View {
        Gate::authorize('viewAny', Consultation::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $attendants = User::query()
            ->where('role', 'attendant')
            ->with('attendantProfile')
            ->whereHas('attendantProfile')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhereHas('attendantProfile', fn ($profile) => $profile
                            ->where('display_name', 'like', $term));
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.modalities.index', [
            'attendants' => $attendants,
            'search' => $search,
            'modalities' => ConsultationModality::cases(),
            'catalog' => $catalog,
            'labelsByModality' => $pricing->labelsByModality(),
        ]);
    }

    public function update(
        Request $request,
        User $user,
        ConsultationModalityCatalog $catalog,
    ): RedirectResponse {

        $profile = $user->attendantProfile;

        abort_unless($user->isAttendant() && $profile !== null, 404);

        Gate::authorize('updateModalities', $profile);

        $validated = $request->validate([
            'offered_modalities' => ['nullable', 'array'],
            'offered_modalities.*' => [Rule::enum(ConsultationModality::class)],
        ]);

        $requested = collect($validated['offered_modalities'] ?? [])
            ->map(static fn ($value): ConsultationModality => ConsultationModality::from((string) $value))
            ->push(ConsultationModality::Text)
            ->unique()
            ->values();

        foreach ($requested as $modality) {
            if (! $catalog->isEnabled($modality)) {
                throw ValidationException::withMessages([
                    'offered_modalities' => 'A modalidade '.$modality->label().' está desativada na infraestrutura.',
                ]);
            }
        }

        $order = array_flip(ConsultationModality::values());

        $values = $requested
            ->sortBy(static fn (ConsultationModality $modality): int => $order[$modality->value])
            ->map(static fn (ConsultationModality $modality): string => $modality->value)
            ->values()
            ->all();

        DB::transaction(function () use ($profile, $values, $request): void {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $before = $locked->offeredModalityValues();

            $locked->offered_modalities = $values;
            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'attendant.modalities_updated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => $values,
                ],
            ]);
        });

        return back()->with('success', 'Modalidades do atendente atualizadas.');
    }
}
