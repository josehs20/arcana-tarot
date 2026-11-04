<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCreditPackagesRequest;
use App\Http\Requests\Admin\UpdatePaymentLimitsRequest;
use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\CreditPackages;
use App\Support\Money;
use App\Support\PaymentLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Valores que a administração define para o checkout de créditos.
 *
 * Reúne o catálogo de pacotes e a faixa de recarga da carteira em uma página
 * própria (/admin/financeiro/pacotes), com item no menu lateral. Nenhum preço
 * vem do navegador: o catálogo guarda a quantidade de créditos e o valor em
 * reais é sempre derivado da tarifa vigente.
 */
class PaymentSettingsController extends Controller
{
    public function index(
        ConsultationPricing $pricing,
        CreditPackages $packages,
        PaymentLimits $limits,
    ): View {
        Gate::authorize('viewAny', Wallet::class);

        $unitPriceCents = $pricing->creditUnitPriceCents();

        $limitsView = [
            'min_cents' => $limits->minimumCents(),
            'max_cents' => $limits->maximumCents(),
            'min_label' => $limits->minimumLabel(),
            'max_label' => $limits->maximumLabel(),
        ];

        return view('admin.finance.settings', [
            'creditPackages' => $packages->configured(),
            'maxPackageCredits' => CreditPackages::MAX_CREDITS,
            'maxPackageLabelLength' => CreditPackages::MAX_LABEL_LENGTH,
            'packageAmountLimits' => $limitsView,
            'rechargeLimits' => $limitsView + [
                'min_input' => number_format($limits->minimumCents() / 100, 2, '.', ''),
                'max_input' => number_format($limits->maximumCents() / 100, 2, '.', ''),
            ],
            'creditUnitPriceCents' => $unitPriceCents,
            'creditUnitPriceLabel' => $unitPriceCents === null ? null : Money::format($unitPriceCents),
        ]);
    }

    /**
     * Substitui o catálogo de pacotes de créditos do checkout.
     *
     * O preço de cada pacote continua sendo derivado no servidor
     * (`créditos × valor do crédito`); a administração define apenas a
     * quantidade de créditos e, opcionalmente, o nome exibido ao cliente.
     */
    public function updatePackages(
        UpdateCreditPackagesRequest $request,
        CreditPackages $packages,
    ): RedirectResponse {
        $saved = $packages->save(
            (array) $request->validated()['packages'],
            $request->user(),
        );

        return redirect()
            ->route('admin.finance.packages.index')
            ->with('success', 'Catálogo de pacotes atualizado com '.count($saved).' pacote(s). O checkout do cliente já usa os novos valores.');
    }

    /**
     * Atualiza a faixa de recarga da carteira (mínimo e máximo em reais).
     */
    public function updateLimits(
        UpdatePaymentLimitsRequest $request,
        PaymentLimits $limits,
    ): RedirectResponse {
        $validated = $request->validated();

        $limits->save(
            (int) $validated['min_amount_cents'],
            (int) $validated['max_amount_cents'],
            $request->user(),
        );

        return redirect()
            ->route('admin.finance.packages.index')
            ->with('success', 'Faixa de recarga atualizada para '.$limits->minimumLabel().' a '.$limits->maximumLabel().'. Pacotes fora da faixa deixam de aparecer no checkout.');
    }
}
