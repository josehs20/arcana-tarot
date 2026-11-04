<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Http\Controllers\Controller;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationModalityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Modalidades e precos realmente oferecidos por cada atendente.
 *
 * Texto e a modalidade base e permanece sempre habilitada. Audio e video
 * dependem tambem da infraestrutura global, portanto nao podem ser atribuidos
 * quando o recurso esta desligado no servidor.
 *
 * O preco por minuto de cada modalidade e definido aqui, por atendente
 * (`attendant_modality_prices`). Nao existe mais tarifa global: a consulta so
 * pode ser solicitada e aceita quando o profissional tem preco configurado.
 */
class ModalityController extends Controller
{
    public function index(
        Request $request,
        ConsultationModalityCatalog $catalog,
    ): View {
        Gate::authorize('viewAny', Consultation::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $attendants = User::query()
            ->where('role', 'attendant')
            ->with('attendantProfile.modalityPrices')
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
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $requested = collect($validated['offered_modalities'] ?? [])
            ->map(static fn ($value): ConsultationModality => ConsultationModality::from((string) $value))
            ->push(ConsultationModality::Text)
            ->unique()
            ->values();

        $prices = [];

        foreach ($requested as $modality) {
            if (! $catalog->isEnabled($modality)) {
                throw ValidationException::withMessages([
                    'offered_modalities' => 'A modalidade '.$modality->label().' está desativada na infraestrutura.',
                ]);
            }

            $price = $validated['prices'][$modality->value] ?? null;

            if ($price === null) {
                throw ValidationException::withMessages([
                    'prices' => 'Informe o preço por minuto de '.$modality->label().' para este atendente.',
                ]);
            }

            $prices[$modality->value] = (int) $price;
        }

        $order = array_flip(ConsultationModality::values());

        $values = $requested
            ->sortBy(static fn (ConsultationModality $modality): int => $order[$modality->value])
            ->map(static fn (ConsultationModality $modality): string => $modality->value)
            ->values()
            ->all();

        DB::transaction(function () use ($profile, $values, $prices, $request): void {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $before = $locked->offeredModalityValues();
            $beforePrices = $locked->pricesByModality();

            $locked->offered_modalities = $values;
            $locked->save();

            foreach ($prices as $modality => $cents) {
                AttendantModalityPrice::query()->updateOrCreate(
                    [
                        'attendant_profile_id' => $locked->getKey(),
                        'modality' => $modality,
                    ],
                    ['price_per_minute_cents' => $cents],
                );
            }

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'attendant.modalities_updated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => $values,
                    'prices_before' => $beforePrices,
                    'prices_after' => $prices,
                ],
            ]);
        });

        return back()->with('success', 'Modalidades e preços por minuto do atendente atualizados. Consultas já iniciadas mantêm o preço congelado.');
    }
}
