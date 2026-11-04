<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Support\CreditWalletManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Histórico completo do próprio cliente.
 *
 * Todas as consultas são filtradas pelo ID autenticado. O valor consumido é
 * lido dos débitos oficiais vinculados a cada consulta, nunca recalculado a
 * partir de valores enviados pelo navegador.
 */
class HistoryController extends Controller
{
    public function __invoke(
        Request $request,
        CreditWalletManager $wallets,
    ): View {
        Gate::authorize('access-client-panel');
        Gate::authorize('viewClientPanel', Consultation::class);
        Gate::authorize('viewAny', Payment::class);

        /** @var User $user */
        $user = $request->user();
        $wallet = $wallets->walletFor($user);

        Gate::authorize('view', $wallet);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'modality' => ['nullable', Rule::enum(ConsultationModality::class)],
        ]);

        $consultations = $user->clientConsultations()
            ->with('attendant.attendantProfile')
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

        $consultationIds = $consultations->getCollection()
            ->pluck('id')
            ->all();

        $consumedByConsultation = $consultationIds === []
            ? collect()
            : CreditLedgerEntry::query()
                ->where('wallet_id', $wallet->getKey())
                ->where('reference_type', (new Consultation)->getMorphClass())
                ->whereIn('reference_id', $consultationIds)
                ->where('type', LedgerEntryType::Debit->value)
                ->selectRaw('reference_id, COALESCE(SUM(amount_cents), 0) AS aggregate')
                ->groupBy('reference_id')
                ->pluck('aggregate', 'reference_id');

        $payments = $user->payments()
            ->latest('id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString();

        $ledgerEntries = $wallet->ledgerEntries()
            ->latest('id')
            ->paginate(20, ['*'], 'ledger_page')
            ->withQueryString();

        return view('client.history', [
            'wallet' => $wallet,
            'consultations' => $consultations,
            'consumedByConsultation' => $consumedByConsultation,
            'payments' => $payments,
            'ledgerEntries' => $ledgerEntries,
            'filters' => $filters,
            'statuses' => ConsultationStatus::cases(),
            'modalities' => ConsultationModality::cases(),
            'totalConsumedCents' => (int) $wallet->ledgerEntries()
                ->where('type', LedgerEntryType::Debit->value)
                ->sum('amount_cents'),
            'totalCreditedCents' => (int) $wallet->ledgerEntries()
                ->where('type', LedgerEntryType::Credit->value)
                ->sum('amount_cents'),
        ]);
    }
}
