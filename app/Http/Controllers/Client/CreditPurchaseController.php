<?php

namespace App\Http\Controllers\Client;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreCreditPurchaseRequest;
use App\Models\Payment;
use App\Support\ActiveConsultationResolver;
use App\Support\ConsultationPricing;
use App\Support\CreditWalletManager;
use App\Support\InfinitePayCheckoutManager;
use App\Support\PaymentLimits;
use App\Support\PaymentManager;
use App\Support\Payments\Exceptions\PixProviderException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CreditPurchaseController extends Controller
{
    public function index(
        Request $request,
        ConsultationPricing $pricing,
        CreditWalletManager $wallets,
        ActiveConsultationResolver $activeConsultations,
        InfinitePayCheckoutManager $checkout,
        PaymentLimits $limits,
    ): View {
        Gate::authorize('access-client-panel');
        Gate::authorize('viewAny', Payment::class);

        $this->rememberConsultationReturn($request, $activeConsultations);

        $wallet = $wallets->walletFor($request->user());

        Gate::authorize('view', $wallet);

        $unitPriceCents = $pricing->creditUnitPriceCents();

        return view('client.credits', [
            'wallet' => $wallet,
            'entries' => $wallet->ledgerEntries()
                ->latest('id')
                ->paginate(15),
            'payments' => $request->user()->payments()
                ->latest('id')
                ->paginate(10, ['*'], 'payments_page')
                ->withQueryString(),
            'pricePerMinuteLabel' => $pricing->pricePerMinuteLabel(),
            'creditUnitPriceCents' => $unitPriceCents,
            'operationId' => (string) Str::uuid(),
            'checkoutEnabled' => $checkout->enabled(),
            'packages' => $checkout->availablePackages(),
            'sandboxEnabled' => $this->sandboxReady(),
            'minAmountCents' => $limits->minimumCents(),
            'maxAmountCents' => $limits->maximumCents(),
            'returnUrl' => $this->consultationReturnUrl($request, $activeConsultations),
        ]);
    }

    public function store(
        StoreCreditPurchaseRequest $request,
        PaymentManager $payments,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $payment = $payments->startRecharge(
                client: $request->user(),
                amountCents: (int) $validated['amount_cents'],
                operationId: (string) $validated['operation_id'],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'amount_cents' => $exception->getMessage(),
            ]);
        } catch (PixProviderException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $message = $payment->status === PaymentStatus::Pending
            ? 'Pedido de recarga criado. O saldo só será liberado após a confirmação server-side do provedor.'
            : 'Este pedido de recarga já foi processado. Confira o status abaixo.';

        return redirect()
            ->route('client.credits.payments.show', $payment)
            ->with('success', $message);
    }

    public function show(
        Request $request,
        Payment $payment,
        ActiveConsultationResolver $activeConsultations,
    ): View {
        Gate::authorize('view', $payment);

        return view('client.payment', [
            'payment' => $payment,
            'sandboxEnabled' => $this->sandboxReady(),
            'returnUrl' => $this->consultationReturnUrl($request, $activeConsultations),
        ]);
    }

    /**
     * Guarda apenas o atendimento ativo do próprio cliente. O ID da URL é só
     * uma intenção: a consulta é sempre revalidada no servidor antes de virar
     * destino de retorno, impedindo redirecionamento para outro atendimento.
     */
    private function rememberConsultationReturn(
        Request $request,
        ActiveConsultationResolver $activeConsultations,
    ): void {
        if (! $request->filled('return_consultation')) {
            return;
        }

        $active = $activeConsultations->forUser($request->user());

        if ($active === null
            || (int) $active->getKey() !== (int) $request->query('return_consultation')
            || ! $active->isClient($request->user())) {
            $request->session()->forget('credits.return_consultation');

            return;
        }

        $request->session()->put('credits.return_consultation', (int) $active->getKey());
    }

    private function consultationReturnUrl(
        Request $request,
        ActiveConsultationResolver $activeConsultations,
    ): ?string {
        $consultationId = $request->session()->get('credits.return_consultation');

        if (! is_numeric($consultationId)) {
            return null;
        }

        $active = $activeConsultations->forUser($request->user());

        if ($active === null || (int) $active->getKey() !== (int) $consultationId) {
            $request->session()->forget('credits.return_consultation');

            return null;
        }

        return route('consultations.workspace', $active);
    }

    private function sandboxReady(): bool
    {
        return (bool) config('payments.sandbox.enabled')
            && trim((string) config('payments.sandbox.webhook_secret')) !== '';
    }
}
