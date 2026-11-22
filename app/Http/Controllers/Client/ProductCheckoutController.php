<?php

namespace App\Http\Controllers\Client;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreProductCheckoutRequest;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use App\Support\ProductCheckoutManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checkout de produtos da vitrine via InfinitePay.
 *
 * O POST cria o pedido no servidor e redireciona para o link do provedor. O
 * GET de retorno apenas reconcilia o status: nenhum parâmetro de URL libera
 * crédito.
 */
class ProductCheckoutController extends Controller
{
    public function store(
        StoreProductCheckoutRequest $request,
        Product $product,
        ProductCheckoutManager $checkout,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $purchase = $checkout->startCheckout(
                client: $request->user(),
                product: $product,
                operationId: (string) $validated['operation_id'],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'product' => $exception->getMessage(),
            ]);
        } catch (InfinitePayException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        if ($purchase->checkout_url === null || $purchase->checkout_url === '') {
            return redirect()
                ->route('client.purchases.index')
                ->with('error', 'O link de pagamento ainda não está disponível. Abra suas compras e tente novamente.');
        }

        return redirect()->away($purchase->checkout_url);
    }

    public function return(Request $request, ProductCheckoutManager $checkout): RedirectResponse
    {
        Gate::authorize('access-client-panel');

        $purchase = Purchase::query()
            ->where('provider', InfinitePayGateway::NAME)
            ->where('user_id', $request->user()->getKey())
            ->where('external_reference', (string) $request->query('order_nsu'))
            ->first();

        if ($purchase === null) {
            return redirect()
                ->route('client.purchases.index')
                ->with('info', 'Não encontramos a compra informada. Confira a lista de compras abaixo.');
        }

        Gate::authorize('view', $purchase);

        try {
            $checkout->reconcile(
                purchase: $purchase,
                transactionNsu: $this->queryString($request, 'transaction_nsu'),
                invoiceSlug: $this->queryString($request, 'slug')
                    ?? $this->queryString($request, 'invoice_slug'),
            );
        } catch (DomainException|InfinitePayException $exception) {
            Log::warning('infinitepay.product_return_reconcile_failed', [
                'purchase_id' => $purchase->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('client.purchases.index')
                ->with('error', 'Recebemos seu retorno, mas ainda não foi possível confirmar o pagamento. Atualize esta página em instantes.');
        }

        $purchase->refresh();

        if ($purchase->status === PurchaseStatus::Paid) {
            return redirect()
                ->route('client.purchases.index')
                ->with('success', 'Pagamento confirmado e créditos liberados na sua carteira.');
        }

        $message = $purchase->status === PurchaseStatus::Cancelled
            ? 'O pagamento não foi aprovado. Nenhum crédito foi liberado.'
            : 'Pagamento em análise. Os créditos são liberados assim que o provedor confirmar.';

        return redirect()
            ->route('client.purchases.index')
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
