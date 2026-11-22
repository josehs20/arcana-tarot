<?php

namespace App\Http\Controllers\Client;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Histórico de compras do próprio cliente.
 *
 * Somente leitura: o status é o oficial do provedor e os valores são a cópia
 * congelada no momento da compra.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access-client-panel');

        $purchases = $request->user()->purchases()
            ->with('product')
            ->latest('id')
            ->paginate(15);

        $paid = $request->user()->purchases()
            ->where('status', PurchaseStatus::Paid)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount_cents), 0) as amount, COALESCE(SUM(credits_amount), 0) as credits')
            ->first();

        return view('client.purchases', [
            'purchases' => $purchases,
            'paidCount' => (int) ($paid->total ?? 0),
            'paidCents' => (int) ($paid->amount ?? 0),
            'paidCredits' => (int) ($paid->credits ?? 0),
        ]);
    }
}
