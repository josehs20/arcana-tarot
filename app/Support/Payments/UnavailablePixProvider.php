<?php

namespace App\Support\Payments;

use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\Data\PixCharge;
use App\Support\Payments\Data\PixChargeRequest;
use App\Support\Payments\Data\PixWebhookEvent;
use App\Support\Payments\Exceptions\PixProviderException;
use App\Support\Payments\Exceptions\PixWebhookException;
use Illuminate\Http\Request;

/**
 * Adaptador neutro usado quando o provedor ativo não é o PIX sandbox (por
 * exemplo, `infinitepay`).
 *
 * Mantém o binding de `PixProvider` sempre resolvível, mas deixa explícito que
 * a cobrança PIX interna está desligada: a rota de webhook e a tela de recarga
 * PIX respondem com erro em vez de simular pagamentos.
 */
final class UnavailablePixProvider implements PixProvider
{
    public function name(): string
    {
        return (string) config('payments.provider');
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function createCharge(PixChargeRequest $request): PixCharge
    {
        throw new PixProviderException('O provedor PIX interno está desligado neste ambiente.');
    }

    public function parseWebhook(Request $request): PixWebhookEvent
    {
        throw new PixWebhookException('O provedor PIX interno está desligado neste ambiente.', 404);
    }
}
