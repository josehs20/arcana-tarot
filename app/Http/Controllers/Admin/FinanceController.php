<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWalletAdjustmentRequest;
use App\Http\Requests\Admin\UpdateConsultationPricingRequest;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\CreditPackages;
use App\Support\CreditWalletManager;
use App\Support\InsufficientCreditBalanceException;
use App\Support\Money;
use App\Support\PaymentLimits;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request, ConsultationPricing $pricing, CreditPackages $packages, PaymentLimits $limits): View
    {
        Gate::authorize('viewAny', Wallet::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(LedgerEntryType::class)],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $entries = CreditLedgerEntry::query()
            ->with(['wallet.user', 'creator'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->whereHas('wallet.user', function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when(
                isset($filters['type']),
                fn ($query) => $query->where('type', $filters['type']),
            )
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.finance.index', [
            'entries' => $entries,
            'filters' => $filters,
            'types' => LedgerEntryType::cases(),
            'totalBalanceCents' => (int) Wallet::query()->sum('balance_cents'),
            'totalCreditedCents' => (int) CreditLedgerEntry::query()
                ->where('type', LedgerEntryType::Credit->value)
                ->sum('amount_cents'),
            'totalDebitedCents' => (int) CreditLedgerEntry::query()
                ->where('type', LedgerEntryType::Debit->value)
                ->sum('amount_cents'),
            'pricing' => [
                'credit_unit_price_cents' => $pricing->creditUnitPriceCents(),
                'minimum_balance_minutes' => $pricing->minimumBalanceMinutes(),
            ],
            'modalities' => ConsultationModality::cases(),
            'creditPackages' => $packages->configured(),
            'availablePackageCount' => $packages->availablePackagesCount(),
            'rechargeLimits' => [
                'min_label' => $limits->minimumLabel(),
                'max_label' => $limits->maximumLabel(),
            ],
        ]);
    }

    public function updatePricing(
        UpdateConsultationPricingRequest $request,
        ConsultationPricing $pricing,
    ): RedirectResponse {
        $validated = $request->validated();

        $before = [
            'credit_unit_price_cents' => $pricing->creditUnitPriceCents(),
            'minimum_balance_minutes' => $pricing->minimumBalanceMinutes(),
        ];

        /*
         * `rate_credits_per_minute*` nao e mais gravado: o preco por minuto vem
         * de cada atendente (`attendant_modality_prices`). As chaves antigas
         * permanecem apenas como leitura historica.
         */
        $after = [
            'credit_unit_price_cents' => (int) $validated['credit_unit_price_cents'],
            'minimum_balance_minutes' => (int) $validated['minimum_balance_minutes'],
        ];

        DB::transaction(function () use ($request, $before, $after): void {
            foreach ($after as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $value, 'type' => 'integer'],
                );
            }

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'consultation_pricing.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ]);
        });

        return redirect()
            ->route('admin.finance.index')
            ->with('success', 'Valor do crédito e saldo mínimo atualizados. O preço por minuto é definido em Modalidades dos atendentes.');
    }

    public function show(User $user): View
    {
        Gate::authorize('viewAny', Wallet::class);

        abort_unless($user->isClient(), 404);

        $wallet = $user->wallet;

        $entries = CreditLedgerEntry::query()
            ->where('wallet_id', $wallet?->getKey() ?? 0)
            ->with('creator')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.finance.show', [
            'user' => $user,
            'wallet' => $wallet,
            'entries' => $entries,
            'operationId' => (string) Str::uuid(),
        ]);
    }

    public function adjust(
        StoreWalletAdjustmentRequest $request,
        User $user,
        CreditWalletManager $wallets,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $entry = $wallets->adjust(
                target: $user,
                actor: $request->user(),
                type: LedgerEntryType::from($validated['type']),
                amountCents: (int) $validated['amount_cents'],
                reason: trim((string) $validated['reason']),
                idempotencyKey: 'admin_adjustment:'.$user->getKey().':'.$validated['operation_id'],
            );
        } catch (InsufficientCreditBalanceException $exception) {
            throw ValidationException::withMessages([
                'amount_cents' => $exception->getMessage(),
            ]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'operation_id' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.finance.wallets.show', $user)
            ->with('success', 'Ajuste registrado. Saldo atual: '.Money::format($entry->balance_after_cents).'.');
    }
}
