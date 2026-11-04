<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Consultation::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'modality' => ['nullable', Rule::enum(ConsultationModality::class)],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $consultations = Consultation::query()
            ->with(['client', 'attendant.attendantProfile', 'commissionEntries'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereHas('client', fn ($client) => $client
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term))
                        ->orWhereHas('attendant', fn ($attendant) => $attendant
                            ->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                });
            })
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['modality']),
                fn ($query) => $query->where('modality', $filters['modality']),
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.consultations.index', [
            'consultations' => $consultations,
            'filters' => $filters,
            'statuses' => ConsultationStatus::cases(),
            'modalities' => ConsultationModality::cases(),
        ]);
    }
}
