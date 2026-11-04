<?php

namespace App\Support\Payments\Contracts;

use App\Support\Payments\Data\PixCharge;
use App\Support\Payments\Data\PixChargeRequest;
use App\Support\Payments\Data\PixWebhookEvent;
use Illuminate\Http\Request;

/**
 * Fronteira única entre o domínio de recargas e o provedor PIX.
 *
 * Nenhum detalhe de assinatura, endpoint ou formato de payload do provedor
 * deve vazar para controllers ou para o livro-razão. Ao trocar de provedor,
 * basta implementar este contrato e alterar o binding em AppServiceProvider.
 */
interface PixProvider
{
    public function name(): string;

    /**
     * Indica se o adaptador tem configuração mínima para operar. A UI e o
     * domínio consultam este método antes de criar um pedido.
     */
    public function isAvailable(): bool;

    public function createCharge(PixChargeRequest $request): PixCharge;

    /**
     * Valida a autenticidade, a idade e o formato da notificação e devolve um
     * evento normalizado. Deve lançar PixWebhookException quando a requisição
     * não for autêntica ou não puder ser interpretada.
     */
    public function parseWebhook(Request $request): PixWebhookEvent;
}
