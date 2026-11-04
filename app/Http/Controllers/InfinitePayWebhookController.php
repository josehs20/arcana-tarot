<?php

namespace App\Http\Controllers;

use App\Enums\PaymentWebhookOutcome;
use App\Support\InfinitePayCheckoutManager;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\Exceptions\InfinitePayWebhookException;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Checkout Integrado da InfinitePay.
 *
 * A notificação é apenas um gatilho: o pedido é sempre reconferido via
 * `payment_check` antes de qualquer crédito. Respostas 2xx evitam reenvios
 * infinitos; 4xx registram a recusa e 503 sinaliza indisponibilidade.
 */
class InfinitePayWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        InfinitePayGateway $gateway,
        InfinitePayCheckoutManager $checkout,
    ): JsonResponse {
        try {
            $notification = $gateway->parseWebhook($request);
            $result = $checkout->handleWebhook($notification);
        } catch (InfinitePayWebhookException $exception) {
            Log::warning('infinitepay.webhook_invalid', [
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'received' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        } catch (InfinitePayException $exception) {
            Log::warning('infinitepay.webhook_provider_unavailable', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'received' => false,
                'message' => 'Provedor de pagamento indisponível.',
            ], 503);
        }

        if ($result->outcome === PaymentWebhookOutcome::Failed) {
            return response()->json([
                'received' => false,
                'message' => 'Notificação registrada como falha.',
                'reason' => $result->reason,
            ], 400);
        }

        return response()->json([
            'received' => true,
            'duplicate' => $result->outcome === PaymentWebhookOutcome::Duplicate,
        ]);
    }
}
