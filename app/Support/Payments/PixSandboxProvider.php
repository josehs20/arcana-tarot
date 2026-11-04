<?php

namespace App\Support\Payments;

use App\Enums\PixEventStatus;
use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\Data\PixCharge;
use App\Support\Payments\Data\PixChargeRequest;
use App\Support\Payments\Data\PixWebhookEvent;
use App\Support\Payments\Exceptions\PixProviderException;
use App\Support\Payments\Exceptions\PixWebhookException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;

/**
 * Provedor PIX exclusivamente de sandbox.
 *
 * Não faz chamadas de rede, não usa credenciais bancárias e não gera um
 * payload PIX válido. O código retornado é deliberadamente identificável como
 * sandbox. Webhooks são assinados com HMAC local para exercitar o mesmo fluxo
 * de validação que um provedor real exigirá.
 */
final class PixSandboxProvider implements PixProvider
{
    public const EVENT_HEADER = 'X-Arcana-Event-Id';

    public const TIMESTAMP_HEADER = 'X-Arcana-Timestamp';

    public const SIGNATURE_HEADER = 'X-Arcana-Signature';

    public function name(): string
    {
        return 'pix_sandbox';
    }

    public function isAvailable(): bool
    {
        return (bool) config('payments.sandbox.enabled')
            && trim((string) config('payments.sandbox.webhook_secret')) !== '';
    }

    public function createCharge(PixChargeRequest $request): PixCharge
    {
        $this->assertConfigured();

        $providerPaymentId = 'sandbox_'.Str::uuid();

        return new PixCharge(
            providerPaymentId: $providerPaymentId,
            copyPaste: 'SANDBOX-NAO-E-PIX|REF='.$request->externalReference
                .'|VALOR_CENTAVOS='.$request->amountCents
                .'|ID='.$providerPaymentId,
            status: PixEventStatus::Pending,
            rawStatus: 'waiting_payment',
            expiresAt: $request->expiresAt,
        );
    }

    public function parseWebhook(Request $request): PixWebhookEvent
    {
        $this->assertConfigured();

        $eventId = trim((string) $request->header(self::EVENT_HEADER, ''));
        $timestampHeader = trim((string) $request->header(self::TIMESTAMP_HEADER, ''));
        $signatureHeader = trim((string) $request->header(self::SIGNATURE_HEADER, ''));

        if ($eventId === '' || ! ctype_digit($timestampHeader) || strlen($timestampHeader) < 10) {
            throw new PixWebhookException('Cabeçalhos obrigatórios ausentes.', 401);
        }

        $timestamp = (int) $timestampHeader;
        $tolerance = max(30, (int) config('payments.sandbox.webhook_tolerance_seconds', 300));

        if (abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            throw new PixWebhookException('Notificação fora da janela de tolerância.', 401);
        }

        if (! preg_match('/^v1=([a-f0-9]{64})$/', $signatureHeader, $matches)) {
            throw new PixWebhookException('Assinatura em formato inválido.', 401);
        }

        $body = $request->getContent();
        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$eventId.'.'.$body,
            (string) config('payments.sandbox.webhook_secret'),
        );

        if (! hash_equals($expected, $matches[1])) {
            throw new PixWebhookException('Assinatura inválida.', 401);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PixWebhookException('Payload JSON inválido.');
        }

        if (! is_array($payload)) {
            throw new PixWebhookException('Payload JSON inválido.');
        }

        $bodyEventId = $payload['id'] ?? null;
        $eventType = $payload['event'] ?? null;
        $payment = $payload['payment'] ?? null;

        if (! is_string($bodyEventId) || ! is_string($eventType) || ! is_array($payment)) {
            throw new PixWebhookException('Evento sem os campos obrigatórios.');
        }

        if (! hash_equals($eventId, $bodyEventId)) {
            throw new PixWebhookException('Identificador do evento divergente.', 401);
        }

        $providerPaymentId = $payment['id'] ?? null;
        $rawStatus = $payment['status'] ?? null;
        $amountCents = $payment['amount_cents'] ?? null;

        if (
            ! is_string($providerPaymentId)
            || trim($providerPaymentId) === ''
            || strlen($providerPaymentId) > 120
            || ! is_string($rawStatus)
            || ! is_int($amountCents)
            || $amountCents <= 0
            || strlen($eventId) > 120
            || strlen($eventType) > 60
        ) {
            throw new PixWebhookException('Dados do pagamento inválidos.');
        }

        return new PixWebhookEvent(
            eventId: $eventId,
            eventType: $eventType,
            providerPaymentId: $providerPaymentId,
            status: $this->normalizeStatus($rawStatus),
            amountCents: $amountCents,
            payloadHash: hash('sha256', $body),
            occurredAt: now(),
        );
    }

    /**
     * Assina um payload no mesmo formato aceito por parseWebhook. Usado apenas
     * pelo comando de simulação local do sandbox.
     *
     * @param  array<string, mixed>  $payload
     * @return array{body: string, headers: array<string, string>}
     */
    public function signWebhookPayload(array $payload, ?int $timestamp = null): array
    {
        $this->assertConfigured();

        $eventId = (string) ($payload['id'] ?? '');

        if ($eventId === '') {
            throw new PixProviderException('O payload de simulação precisa de um identificador de evento.');
        }

        $timestamp ??= now()->getTimestamp();

        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PixProviderException('Não foi possível serializar o evento de sandbox.', previous: $exception);
        }

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$eventId.'.'.$body,
            (string) config('payments.sandbox.webhook_secret'),
        );

        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                self::EVENT_HEADER => $eventId,
                self::TIMESTAMP_HEADER => (string) $timestamp,
                self::SIGNATURE_HEADER => 'v1='.$signature,
            ],
        ];
    }

    private function normalizeStatus(string $status): PixEventStatus
    {
        return match (strtolower(trim($status))) {
            'approved', 'paid', 'confirmed', 'received' => PixEventStatus::Paid,
            'declined', 'rejected', 'failed' => PixEventStatus::Declined,
            'expired' => PixEventStatus::Expired,
            'pending', 'waiting_payment' => PixEventStatus::Pending,
            default => throw new PixWebhookException('Status de pagamento desconhecido.'),
        };
    }

    private function assertConfigured(): void
    {
        if (! (bool) config('payments.sandbox.enabled')) {
            throw new PixProviderException('O PIX sandbox está desabilitado neste ambiente.');
        }

        if (trim((string) config('payments.sandbox.webhook_secret')) === '') {
            throw new PixProviderException('Configure PAYMENT_SANDBOX_WEBHOOK_SECRET para usar o sandbox.');
        }
    }
}
