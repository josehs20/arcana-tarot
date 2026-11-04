<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Listagem administrativa de recargas. Não exibe payload PIX, segredo,
 * conteúdo de webhook ou qualquer credencial do provedor.
 */
class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAnyAsAdmin', Payment::class);

        $rules = [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];

        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        $filters = $request->validate($rules);
        $search = trim((string) ($filters['q'] ?? ''));

        $query = Payment::query()
            ->with('user')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('external_reference', 'like', $term)
                        ->orWhere('provider_payment_id', 'like', $term)
                        ->orWhereHas('user', function ($query) use ($term): void {
                            $query->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                });
            })
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                filled($filters['date_from'] ?? null),
                fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            );

        $confirmedQuery = (clone $query)->where('status', PaymentStatus::Confirmed->value);

        return view('admin.payments.index', [
            'payments' => $query
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'filters' => $filters,
            'statuses' => PaymentStatus::cases(),
            'confirmedCount' => (clone $confirmedQuery)->count(),
            'confirmedCents' => (int) (clone $confirmedQuery)->sum('amount_cents'),
        ]);
    }
}
