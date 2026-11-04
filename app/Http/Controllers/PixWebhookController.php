<?php

namespace App\Http\Controllers;

use App\Enums\PaymentWebhookOutcome;
use App\Support\PaymentManager;
use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\Exceptions\PixProviderException;
use App\Support\Payments\Exceptions\PixWebhookException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PixWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixProvider $provider,
        PaymentManager $payments,
    ): JsonResponse {
        try {
            $event = $provider->parseWebhook($request);
            $result = $payments->handleWebhook($event);
        } catch (PixWebhookException $exception) {
            Log::warning('pix.webhook_rejected', [
                'provider' => $provider->name(),
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'received' => false,
                'message' => $exception->getMessage(),
            ], $exception->status);
        } catch (PixProviderException $exception) {
            Log::warning('pix.webhook_provider_unavailable', [
                'provider' => $provider->name(),
                'reason' => $exception->getMessage(),
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
            ], $result->reason === 'unknown_payment' ? 404 : 422);
        }

        return response()->json([
            'received' => true,
            'duplicate' => $result->outcome === PaymentWebhookOutcome::Duplicate,
        ]);
    }
}
