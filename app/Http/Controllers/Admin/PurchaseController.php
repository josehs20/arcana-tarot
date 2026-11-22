<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Vendas de produtos, somente leitura.
 *
 * O status vem do webhook do provedor; esta tela apenas filtra e totaliza. Não
 * existe ação administrativa para marcar uma compra como paga.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Purchase::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(PurchaseStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $query = Purchase::query()
            ->with('user')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('product_title', 'like', '%'.$search.'%')
                        ->orWhere('external_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when(
                filled($filters['status'] ?? null),
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

        $confirmedCount = (clone $query)->where('status', PurchaseStatus::Paid)->count();
        $confirmedCents = (int) (clone $query)->where('status', PurchaseStatus::Paid)->sum('amount_cents');

        $purchases = $query
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.purchases.index', [
            'purchases' => $purchases,
            'filters' => $filters,
            'statuses' => PurchaseStatus::cases(),
            'confirmedCount' => $confirmedCount,
            'confirmedCents' => $confirmedCents,
        ]);
    }
}
