<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionEntryType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReverseCommissionEntryRequest;
use App\Http\Requests\Admin\UpdateAttendantCommissionRateRequest;
use App\Models\CommissionLedgerEntry;
use App\Models\User;
use App\Support\CommissionManager;
use App\Support\CommissionRateManager;
use App\Support\CommissionSummary;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CommissionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CommissionLedgerEntry::class);

        $rules = [
            'attendant_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'consultation_id' => ['nullable', 'integer', Rule::exists('consultations', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];

        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        $filters = $request->validate($rules);

        $query = $this->filteredQuery($filters);
        $totals = $this->totals($query);

        $entries = (clone $query)
            ->with(['attendant.attendantProfile', 'consultation', 'actor', 'reversal'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.commissions.index', [
            'entries' => $entries,
            'filters' => $filters,
            'attendants' => $this->attendantOptions(),
            'totals' => $totals,
        ]);
    }

    public function attendant(User $user): View
    {
        $profile = $user->attendantProfile;

        abort_unless($user->isAttendant() && $profile !== null, 404);

        Gate::authorize('updateCommission', $profile);

        $query = CommissionLedgerEntry::query()->where('attendant_id', $user->getKey());

        $entries = (clone $query)
            ->with(['consultation', 'reversal'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.commissions.attendant', [
            'attendant' => $user,
            'profile' => $profile,
            'entries' => $entries,
            'totals' => $this->totals($query),
            'summary' => CommissionSummary::forAttendant($user),
        ]);
    }

    public function updateRate(
        UpdateAttendantCommissionRateRequest $request,
        User $user,
        CommissionRateManager $rates,
    ): RedirectResponse {
        try {
            $rates->update($user, $request->user(), $request->rateBps());
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'commission_rate' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.commissions.attendant', $user)
            ->with('success', 'Taxa de comissão atualizada. Consultas já iniciadas preservam o snapshot anterior.');
    }

    public function reverse(
        ReverseCommissionEntryRequest $request,
        CommissionLedgerEntry $entry,
        CommissionManager $commissions,
    ): RedirectResponse {
        try {
            $reversal = $commissions->reverse($entry, $request->user(), (string) $request->validated('reason'));
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            'Estorno registrado como lançamento separado no valor de '
                .Money::format($reversal->commission_amount_cents).'.',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CommissionLedgerEntry>
     */
    private function filteredQuery(array $filters): Builder
    {
        return CommissionLedgerEntry::query()
            ->when(
                isset($filters['attendant_id']),
                fn (Builder $query) => $query->where('attendant_id', $filters['attendant_id']),
            )
            ->when(
                isset($filters['consultation_id']),
                fn (Builder $query) => $query->where('consultation_id', $filters['consultation_id']),
            )
            ->when(
                filled($filters['date_from'] ?? null),
                fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            );
    }

    /**
     * @param  Builder<CommissionLedgerEntry>  $query
     * @return array{gross_cents: int, commission_cents: int, net_cents: int, reversed_cents: int}
     */
    private function totals(Builder $query): array
    {
        $grossCents = (int) (clone $query)
            ->where('type', CommissionEntryType::Accrual->value)
            ->sum('base_amount_cents');

        $commissionCents = (int) (clone $query)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN -commission_amount_cents ELSE commission_amount_cents END), 0) AS aggregate',
                [CommissionEntryType::Reversal->value],
            )
            ->value('aggregate');

        $reversedCents = (int) (clone $query)
            ->where('type', CommissionEntryType::Reversal->value)
            ->sum('commission_amount_cents');

        return [
            'gross_cents' => $grossCents,
            'commission_cents' => $commissionCents,
            'net_cents' => $grossCents - $commissionCents,
            'reversed_cents' => $reversedCents,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function attendantOptions(): Collection
    {
        return User::query()
            ->where('role', UserRole::Attendant->value)
            ->whereHas('attendantProfile')
            ->with('attendantProfile')
            ->orderBy('name')
            ->get();
    }
}
