<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\PixSandboxProvider;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SimulatePixSandboxWebhook extends Command
{
    protected $signature = 'pix:sandbox-simulate
                            {payment : ID do pedido de recarga}
                            {status=approved : approved, declined ou expired}';

    protected $description = 'Envia um webhook HMAC de sandbox para testar a confirmação server-side (não movimenta dinheiro real)';

    public function handle(PixProvider $provider): int
    {
        if (! $provider instanceof PixSandboxProvider) {
            $this->error('Este comando só funciona com o provedor pix_sandbox.');

            return self::FAILURE;
        }

        $status = strtolower((string) $this->argument('status'));
        $providerStatus = match ($status) {
            'approved' => 'approved',
            'declined' => 'declined',
            'expired' => 'expired',
            default => null,
        };

        if ($providerStatus === null) {
            $this->error('Status inválido. Use approved, declined ou expired.');

            return self::FAILURE;
        }

        $payment = Payment::query()->find((int) $this->argument('payment'));

        if ($payment === null) {
            $this->error('Pedido de recarga não encontrado.');

            return self::FAILURE;
        }

        if ($payment->provider !== $provider->name() || $payment->provider_payment_id === null) {
            $this->error('O pedido não pertence ao sandbox ou ainda não possui referência do provedor.');

            return self::FAILURE;
        }

        if ($payment->status !== PaymentStatus::Pending) {
            $this->warn('O pedido já está em estado final; o evento será enviado, mas não deverá alterar o saldo.');
        }

        return $this->sendSimulation($provider, $payment, $providerStatus);
    }

    private function sendSimulation(PixSandboxProvider $provider, Payment $payment, string $status): int
    {
        $eventId = 'evt_sandbox_'.Str::uuid();

        $signed = $provider->signWebhookPayload([
            'id' => $eventId,
            'event' => 'payment.updated',
            'payment' => [
                'id' => $payment->provider_payment_id,
                'status' => $status,
                'amount_cents' => $payment->amount_cents,
            ],
        ]);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($signed['headers'] as $name => $value) {
            if (str_starts_with($name, 'X-Arcana-')) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }

        $request = Request::create(
            uri: '/webhooks/pix',
            method: 'POST',
            server: $server,
            content: $signed['body'],
        );

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->info("Webhook de sandbox aceito (HTTP {$statusCode}): {$response->getContent()}");

            return self::SUCCESS;
        }

        $this->error("Webhook de sandbox recusado (HTTP {$statusCode}): {$response->getContent()}");

        return self::FAILURE;
    }
}
