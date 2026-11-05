<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFirstAccessBonusRequest;
use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\FirstAccessBonus;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Bônus de créditos no primeiro acesso (/admin/financeiro/bonus).
 *
 * Segue o padrão de "Pacotes e recarga": valores em `settings`, auditoria em
 * `audit_logs` e nenhum valor vindo do navegador vira saldo — o crédito é
 * calculado pela tarifa vigente e lançado pelo CreditWalletManager.
 */
class RegistrationBonusController extends Controller
{
    public function edit(FirstAccessBonus $bonus): View
    {
        Gate::authorize('viewAny', Wallet::class);

        $summary = $bonus->summary();
        $unitPriceCents = app(ConsultationPricing::class)->creditUnitPriceCents();

        return view('admin.finance.bonus', [
            'bonus' => $summary,
            'bonusAmountLabel' => $summary['amount_cents'] === null ? null : Money::format($summary['amount_cents']),
            'grantedAmountLabel' => Money::format($summary['granted_cents']),
            'creditUnitPriceLabel' => $unitPriceCents === null ? null : Money::format($unitPriceCents),
            'maxCredits' => FirstAccessBonus::MAX_CREDITS,
        ]);
    }

    public function update(UpdateFirstAccessBonusRequest $request, FirstAccessBonus $bonus): RedirectResponse
    {
        $validated = $request->validated();

        $bonus->save(
            (bool) $validated['enabled'],
            (int) $validated['credits'],
            $request->user(),
        );

        $message = (bool) $validated['enabled']
            ? sprintf('Bônus de primeiro acesso ativado com %d crédito(s).', (int) $validated['credits'])
            : 'Bônus de primeiro acesso desativado. Nenhum novo cliente receberá créditos.';

        return redirect()
            ->route('admin.finance.bonus.edit')
            ->with('success', $message);
    }
}
