<?php

namespace App\Http\Controllers\Client;

use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreCreditCheckoutRequest;
use App\Models\Payment;
use App\Support\InfinitePayCheckoutManager;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checkout de créditos via InfinitePay.
 *
 * O navegador nunca informa valor: recebe um link assinado pelo provedor a
 * partir do pacote escolhido e volta para `return` apenas para ver o status.
 * A liberação de saldo depende sempre de `payment_check` no servidor.
 */
class CreditCheckoutController extends Controller
{
    public function store(
        StoreCreditCheckoutRequest $request,
        InfinitePayCheckoutManager $checkout,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $payment = $checkout->startCheckout(
                client: $request->user(),
                packageKey: (string) $validated['package'],
                operationId: (string) $validated['operation_id'],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'package' => $exception->getMessage(),
            ]);
        } catch (InfinitePayException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        if ($payment->checkout_url === null || $payment->checkout_url === '') {
            return redirect()
                ->route('client.credits.payments.show', $payment)
                ->with('error', 'O link de pagamento ainda não está disponível. Abra os detalhes da recarga e tente novamente.');
        }

        return redirect()->away($payment->checkout_url);
    }

    /**
     * Retorno do navegador após o checkout. Nenhum parâmetro da URL concede
     * crédito: o pedido é sempre reconferido junto ao provedor.
     */
    public function return(Request $request, InfinitePayCheckoutManager $checkout): RedirectResponse
    {
        Gate::authorize('access-client-panel');

        $payment = Payment::query()
            ->where('provider', InfinitePayGateway::NAME)
            ->where('user_id', $request->user()->getKey())
            ->where('external_reference', (string) $request->query('order_nsu'))
            ->first();

        if ($payment === null) {
            return redirect()
                ->route('client.credits.index')
                ->with('info', 'Não encontramos a recarga informada. Confira a lista de recargas abaixo.');
        }

        Gate::authorize('view', $payment);

        try {
            $result = $checkout->reconcile(
                payment: $payment,
                transactionNsu: $this->queryString($request, 'transaction_nsu'),
                invoiceSlug: $this->queryString($request, 'slug')
                    ?? $this->queryString($request, 'invoice_slug'),
            );
        } catch (DomainException|InfinitePayException $exception) {
            Log::warning('infinitepay.return_reconcile_failed', [
                'payment_id' => $payment->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('client.credits.payments.show', $payment)
                ->with('error', 'Recebemos seu retorno, mas ainda não foi possível confirmar o pagamento. Atualize esta página em instantes.');
        }

        $payment->refresh();

        if ($payment->status === PaymentStatus::Confirmed) {
            return redirect()
                ->route('client.credits.payments.show', $payment)
                ->with('success', 'Pagamento confirmado e créditos liberados na sua carteira.');
        }

        $message = match ($result->outcome) {
            PaymentWebhookOutcome::Failed => 'O pagamento não foi aprovado. Nenhum crédito foi liberado.',
            PaymentWebhookOutcome::Duplicate, PaymentWebhookOutcome::Ignored => 'Este pagamento já havia sido processado. Confira o status abaixo.',
            default => 'Pagamento em análise. Os créditos são liberados assim que o provedor confirmar.',
        };

        return redirect()
            ->route('client.credits.payments.show', $payment)
            ->with('info', $message);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
