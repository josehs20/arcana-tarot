<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\User;
use App\Support\AttendantModalityManager;
use App\Support\ConsultationModalityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        AttendantModalityManager $modalities,
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

        $modalities->updateOfferedModalities(
            $profile,
            $request->user(),
            $validated['offered_modalities'] ?? [],
            $validated['prices'] ?? [],
        );

        return back()->with('success', 'Modalidades e preços por minuto do atendente atualizados. Consultas já iniciadas mantêm o preço congelado.');
    }
}
