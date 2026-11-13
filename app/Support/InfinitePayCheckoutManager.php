<?php

namespace App\Support;

use App\Enums\InfinitePayEvent;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\InfinitePay\Data\CheckoutLinkRequest;
use App\Support\Payments\InfinitePay\Data\PaymentCheck;
use App\Support\Payments\InfinitePay\Data\PaymentCheckRequest;
use App\Support\Payments\InfinitePay\Data\WebhookNotification;
use App\Support\Payments\InfinitePay\InfinitePayGateway;
use App\Support\Payments\PaymentWebhookResult;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orquestra a compra de créditos pelo Checkout Integrado da InfinitePay.
 *
 * Regras invioláveis:
 * - o valor e a quantidade de créditos são sempre calculados no servidor;
 * - nenhum crédito é liberado sem `payment_check` aprovado (o webhook é apenas
 *   um gatilho de conferência);
 * - a confirmação e o crédito acontecem na mesma transação, com lock de linha
 *   e chave idempotente, então notificações repetidas não duplicam saldo;
 * - estorno não remove créditos automaticamente: fica registrado para análise.
 */
final class InfinitePayCheckoutManager
{
    public function __construct(
        private readonly CreditWalletManager $wallets,
        private readonly ConsultationPricing $pricing,
        private readonly CreditPackages $packages,
        private readonly InfinitePayGateway $gateway,
    ) {}

    /**
     * O checkout só aparece quando o provedor ativo é a InfinitePay, o handle
     * está configurado e existe preço de crédito definido pela administração.
     */
    public function enabled(): bool
    {
        return config('payments.provider') === InfinitePayGateway::NAME
            && $this->gateway->isConfigured()
            && $this->pricing->creditUnitPriceCents() !== null;
    }

    /**
     * @return array<string, array{key: string, credits: int, amount_cents: int, credit_unit_price_cents: int, label: string}>
     */
    public function availablePackages(): array
    {
        return $this->enabled() ? $this->packages->available() : [];
    }

    /**
     * Cria (ou reaproveita, pela chave idempotente) o pedido de recarga e
     * devolve o link de checkout da InfinitePay.
     */
    public function startCheckout(User $client, string $packageKey, string $operationId): Payment
    {
        if (! $client->is_active || ! $client->isClient()) {
            throw new DomainException('A recarga é exclusiva de clientes ativos.');
        }

        if (! $this->enabled()) {
            throw new DomainException('O checkout InfinitePay não está configurado neste ambiente.');
        }

        $package = $this->packages->find($packageKey);

        if ($package === null) {
            throw new InvalidArgumentException('Escolha um pacote de créditos válido.');
        }

        $operationId = trim($operationId);

        if ($operationId === '') {
            throw new InvalidArgumentException('A operação de recarga é obrigatória.');
        }

        $idempotencyKey = 'infinitepay_checkout:'.$client->getKey().':'.$operationId;
        $existing = $this->findExistingCheckout($client, $package, $idempotencyKey);

        if ($existing !== null) {
            return $existing->checkout_url === null && $existing->status === PaymentStatus::Pending
                ? $this->attachCheckoutLink($existing)
                : $existing;
        }

        $payment = DB::transaction(function () use ($client, $package, $idempotencyKey): Payment {
            $payment = Payment::query()->create([
                'user_id' => $client->getKey(),
                'provider' => InfinitePayGateway::NAME,
                'external_reference' => 'recharge_'.Str::ulid(),
                'amount_cents' => $package['amount_cents'],
                'credits' => $package['credits'],
                'credit_unit_price_cents' => $package['credit_unit_price_cents'],
                'package_key' => $package['key'],
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMinutes(max(5, (int) config('payments.infinitepay.charge_ttl_minutes', 1440))),
            ]);

            $this->audit($payment, 'payment.created', $client->getKey(), [
                'user_id' => $client->getKey(),
                'provider' => InfinitePayGateway::NAME,
                'package_key' => $package['key'],
                'amount_cents' => $package['amount_cents'],
                'credits' => $package['credits'],
                'credit_unit_price_cents' => $package['credit_unit_price_cents'],
                'external_reference' => $payment->external_reference,
                'from' => null,
                'to' => PaymentStatus::Pending->value,
            ]);

            return $payment;
        });

        return $this->attachCheckoutLink($payment);
    }

    /**
     * Notificação do webhook: localiza o pedido, confere no provedor quando o
     * evento libera crédito e só então aplica a transição.
     */
    public function handleWebhook(WebhookNotification $notification): PaymentWebhookResult
    {
        $payment = Payment::query()
            ->where('provider', InfinitePayGateway::NAME)
            ->where('external_reference', $notification->orderNsu)
            ->first();

        if ($payment === null) {
            return $this->recordUnknownOrder($notification);
        }

        $check = null;

        if ($notification->event->requiresServerConfirmation()) {
            $check = $this->verifyPayment($payment, $notification);
        }

        return $this->apply($payment->getKey(), $notification, $check);
    }

    /**
     * Reconciliação do retorno do navegador (`redirect_url`). Nunca libera
     * crédito por confiança no retorno: consulta o provedor e usa a mesma
     * transação do webhook.
     */
    public function reconcile(Payment $payment, ?string $transactionNsu, ?string $invoiceSlug): PaymentWebhookResult
    {
        if ($payment->provider !== InfinitePayGateway::NAME) {
            throw new DomainException('Este pedido não pertence ao checkout InfinitePay.');
        }

        $payment->refresh();

        if ($payment->status !== PaymentStatus::Pending) {
            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Ignored,
                payment: $payment,
                reason: 'payment_already_'.$payment->status->value,
            );
        }

        $transactionNsu = $this->cleanReference($transactionNsu) ?? $payment->transaction_nsu;
        $invoiceSlug = $this->cleanReference($invoiceSlug) ?? $payment->invoice_slug;

        $notification = new WebhookNotification(
            eventId: 'return_'.Str::ulid(),
            event: InfinitePayEvent::Paid,
            orderNsu: (string) $payment->external_reference,
            amountCents: $payment->amount_cents,
            payloadHash: hash('sha256', 'return|'.$payment->getKey().'|'.($transactionNsu ?? '')),
            transactionNsu: $transactionNsu,
            invoiceSlug: $invoiceSlug,
            paidAmountCents: $payment->amount_cents,
            occurredAt: now(),
        );

        $check = $transactionNsu === null || $invoiceSlug === null
            ? null
            : $this->gateway->checkPayment(new PaymentCheckRequest(
                orderNsu: (string) $payment->external_reference,
                transactionNsu: $transactionNsu,
                invoiceSlug: $invoiceSlug,
            ));

        return $this->apply($payment->getKey(), $notification, $check);
    }

    private function attachCheckoutLink(Payment $payment): Payment
    {
        try {
            $link = $this->gateway->createCheckoutLink(new CheckoutLinkRequest(
                orderNsu: (string) $payment->external_reference,
                amountCents: $payment->amount_cents,
                description: 'Recarga '.SiteContent::currentName().' - '.$payment->credits.' créditos',
                redirectUrl: route('client.credits.checkout.return'),
                webhookUrl: $this->webhookUrl(),
                customerName: $payment->user?->name,
                customerEmail: $payment->user?->email,
                customerPhone: $payment->user?->phone,
                validUntil: $payment->expires_at,
            ));
        } catch (InfinitePayException $exception) {
            $this->markProviderFailure($payment);

            Log::error('infinitepay.checkout_creation_failed', [
                'payment_id' => $payment->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new InfinitePayException(
                'Não foi possível gerar o link de pagamento agora. Tente novamente em instantes.',
                $exception,
            );
        }

        $payment->forceFill([
            'checkout_url' => $link->url,
            'invoice_slug' => $link->invoiceSlug,
            'raw_status' => $link->rawStatus,
        ])->save();

        return $payment->refresh();
    }

    private function verifyPayment(Payment $payment, WebhookNotification $notification): ?PaymentCheck
    {
        $transactionNsu = $this->cleanReference($notification->transactionNsu) ?? $payment->transaction_nsu;
        $invoiceSlug = $this->cleanReference($notification->invoiceSlug) ?? $payment->invoice_slug;

        if ($transactionNsu === null || $invoiceSlug === null) {
            return null;
        }

        return $this->gateway->checkPayment(new PaymentCheckRequest(
            orderNsu: (string) $payment->external_reference,
            transactionNsu: $transactionNsu,
            invoiceSlug: $invoiceSlug,
        ));
    }

    /**
     * @param  array{key: string, credits: int, amount_cents: int, credit_unit_price_cents: int, label: string}  $package
     */
    private function findExistingCheckout(User $client, array $package, string $idempotencyKey): ?Payment
    {
        $existing = Payment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return null;
        }

        if (
            $existing->user_id !== $client->getKey()
            || $existing->amount_cents !== $package['amount_cents']
            || $existing->credits !== $package['credits']
            || $existing->provider !== InfinitePayGateway::NAME
        ) {
            throw new DomainException('Esta operação de recarga já foi usada com outros dados.');
        }

        return $existing;
    }

    /**
     * Aplica a notificação dentro de uma transação com lock no pedido e na
     * própria notificação (idempotência por `provider_event_id`).
     */
    private function apply(int $paymentId, WebhookNotification $notification, ?PaymentCheck $check): PaymentWebhookResult
    {
        return DB::transaction(function () use ($paymentId, $notification, $check): PaymentWebhookResult {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);

            if ($payment === null) {
                return $this->recordUnknownOrder($notification);
            }

            $duplicate = PaymentWebhookEvent::query()
                ->where('provider_event_id', $notification->eventId)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Duplicate,
                    payment: $duplicate->payment,
                    event: $duplicate,
                    reason: 'duplicate_event',
                );
            }

            $from = $payment->status;

            if ($from === PaymentStatus::Failed
                || $from === PaymentStatus::Refunded
                || $from === PaymentStatus::Expired) {
                return $this->ignored($payment, $notification, 'payment_already_'.$from->value);
            }

            if ($from === PaymentStatus::Confirmed) {
                return $notification->event === InfinitePayEvent::Refunded
                    ? $this->applyRefund($payment, $notification)
                    : $this->ignored($payment, $notification, 'payment_already_confirmed');
            }

            // Daqui em diante o pedido está pendente.
            if ($notification->event === InfinitePayEvent::Refunded) {
                return $this->ignored($payment, $notification, 'refund_without_payment');
            }

            if ($notification->event === InfinitePayEvent::Canceled
                || $notification->event === InfinitePayEvent::PaymentFailed) {
                return $this->applyDecline($payment, $notification);
            }

            if ($notification->event === InfinitePayEvent::Created) {
                return $this->ignored($payment, $notification, 'awaiting_payment', 'created');
            }

            if ($notification->amountCents !== $payment->amount_cents) {
                return $this->rejected($payment, $notification, 'amount_mismatch');
            }

            if ($check === null) {
                return $this->rejected($payment, $notification, 'missing_reference');
            }

            if (! $check->confirmed()) {
                return $this->rejected($payment, $notification, 'not_paid_confirmed', [
                    'check_success' => $check->success,
                    'check_paid' => $check->paid,
                ]);
            }

            if ($check->amountCents === null) {
                return $this->rejected($payment, $notification, 'amount_unavailable');
            }

            if ($check->amountCents !== $payment->amount_cents) {
                return $this->rejected($payment, $notification, 'amount_mismatch', [
                    'received_amount_cents' => $check->amountCents,
                ]);
            }

            return $this->confirm($payment, $notification, $check, $from);
        });
    }

    private function confirm(
        Payment $payment,
        WebhookNotification $notification,
        PaymentCheck $check,
        PaymentStatus $from,
    ): PaymentWebhookResult {
        $entry = $this->wallets->credit(
            $payment->user,
            $payment->amount_cents,
            'payment:'.$payment->getKey(),
            $payment,
        );

        $payment->forceFill([
            'status' => PaymentStatus::Confirmed,
            'raw_status' => $notification->event->value,
            'failure_reason' => null,
            'paid_at' => now(),
            'verified_at' => now(),
            'transaction_nsu' => $this->cleanReference($notification->transactionNsu) ?? $payment->transaction_nsu,
            'invoice_slug' => $this->cleanReference($notification->invoiceSlug) ?? $payment->invoice_slug,
            'capture_method' => $check->captureMethod ?? $notification->captureMethod ?? $payment->capture_method,
        ])->save();

        $this->audit($payment, 'payment.confirmed', null, [
            'user_id' => $payment->user_id,
            'provider_event_id' => $notification->eventId,
            'amount_cents' => $payment->amount_cents,
            'paid_amount_cents' => $check->paidAmountCents,
            'credits' => $payment->credits,
            'credit_entry_id' => $entry->getKey(),
            'transaction_nsu' => $payment->transaction_nsu,
            'invoice_slug' => $payment->invoice_slug,
            'capture_method' => $payment->capture_method,
            'from' => $from->value,
            'to' => PaymentStatus::Confirmed->value,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            payment: $payment->refresh(),
            event: $this->storeEvent($payment, $notification, PaymentEventStatus::Processed),
        );
    }

    private function applyDecline(Payment $payment, WebhookNotification $notification): PaymentWebhookResult
    {
        $reason = $notification->event === InfinitePayEvent::Canceled ? 'provider_canceled' : 'provider_declined';

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'raw_status' => $notification->event->value,
            'failure_reason' => $reason,
        ])->save();

        $this->audit($payment, 'payment.failed', null, [
            'user_id' => $payment->user_id,
            'provider_event_id' => $notification->eventId,
            'amount_cents' => $payment->amount_cents,
            'from' => PaymentStatus::Pending->value,
            'to' => PaymentStatus::Failed->value,
            'reason' => $reason,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            payment: $payment,
            event: $this->storeEvent($payment, $notification, PaymentEventStatus::Processed),
        );
    }

    /**
     * Estorno de pedido já confirmado. Os créditos não são removidos
     * automaticamente (podem ter sido consumidos em atendimento): o pedido fica
     * marcado como estornado e a auditoria sinaliza análise manual.
     */
    private function applyRefund(Payment $payment, WebhookNotification $notification): PaymentWebhookResult
    {
        $wallet = $this->wallets->walletFor($payment->user);

        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'raw_status' => 'refunded',
        ])->save();

        $this->audit($payment, 'payment.refunded', null, [
            'user_id' => $payment->user_id,
            'provider_event_id' => $notification->eventId,
            'amount_cents' => $payment->amount_cents,
            'credits' => $payment->credits,
            'wallet_balance_cents' => $wallet->balance_cents,
            'transaction_nsu' => $this->cleanReference($notification->transactionNsu) ?? $payment->transaction_nsu,
            'from' => PaymentStatus::Confirmed->value,
            'to' => PaymentStatus::Refunded->value,
            'manual_review_required' => true,
        ]);

        Log::warning('infinitepay.payment_refunded', [
            'payment_id' => $payment->getKey(),
            'user_id' => $payment->user_id,
            'credits' => $payment->credits,
            'wallet_balance_cents' => $wallet->balance_cents,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            payment: $payment,
            event: $this->storeEvent($payment, $notification, PaymentEventStatus::Processed),
        );
    }

    private function ignored(
        Payment $payment,
        WebhookNotification $notification,
        string $reason,
        ?string $rawStatus = null,
    ): PaymentWebhookResult {
        if ($rawStatus !== null && $payment->status === PaymentStatus::Pending && $payment->raw_status !== $rawStatus) {
            $payment->forceFill(['raw_status' => $rawStatus])->save();
        }

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Ignored,
            payment: $payment,
            event: $this->storeEvent($payment, $notification, PaymentEventStatus::Ignored, $reason),
            reason: $reason,
        );
    }

    /**
     * Notificação que não libera crédito: valor divergente, `payment_check`
     * reprovado ou ausência dos identificadores de consulta.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function rejected(
        Payment $payment,
        WebhookNotification $notification,
        string $reason,
        array $metadata = [],
    ): PaymentWebhookResult {
        $record = $this->storeEvent($payment, $notification, PaymentEventStatus::Failed, $reason);

        $this->audit($payment, 'payment.webhook_rejected', null, array_merge([
            'provider_event_id' => $notification->eventId,
            'reason' => $reason,
            'order_nsu' => $notification->orderNsu,
            'expected_amount_cents' => $payment->amount_cents,
            'received_amount_cents' => $notification->amountCents,
            'payload_hash' => $notification->payloadHash,
        ], $metadata));

        Log::warning('infinitepay.webhook_rejected', [
            'payment_id' => $payment->getKey(),
            'reason' => $reason,
            'order_nsu' => $notification->orderNsu,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Failed,
            payment: $payment,
            event: $record,
            reason: $reason,
        );
    }

    private function recordUnknownOrder(WebhookNotification $notification): PaymentWebhookResult
    {
        $existing = PaymentWebhookEvent::query()
            ->where('provider_event_id', $notification->eventId)
            ->first();

        if ($existing !== null) {
            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Duplicate,
                payment: $existing->payment,
                event: $existing,
                reason: 'duplicate_event',
            );
        }

        $record = $this->storeEvent(null, $notification, PaymentEventStatus::Failed, 'unknown_order');

        $this->audit(null, 'payment.webhook_rejected', null, [
            'provider' => InfinitePayGateway::NAME,
            'provider_event_id' => $notification->eventId,
            'reason' => 'unknown_order',
            'order_nsu' => $notification->orderNsu,
            'received_amount_cents' => $notification->amountCents,
            'payload_hash' => $notification->payloadHash,
        ]);

        Log::warning('infinitepay.webhook_unknown_order', [
            'order_nsu' => $notification->orderNsu,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Failed,
            payment: null,
            event: $record,
            reason: 'unknown_order',
        );
    }

    private function markProviderFailure(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

            if ($locked === null || $locked->status !== PaymentStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'provider_error',
            ])->save();

            $this->audit($locked, 'payment.provider_failed', null, [
                'user_id' => $locked->user_id,
                'amount_cents' => $locked->amount_cents,
                'from' => PaymentStatus::Pending->value,
                'to' => PaymentStatus::Failed->value,
                'reason' => 'provider_error',
            ]);
        });
    }

    private function storeEvent(
        ?Payment $payment,
        WebhookNotification $notification,
        PaymentEventStatus $status,
        ?string $failureReason = null,
    ): PaymentWebhookEvent {
        return PaymentWebhookEvent::query()->create([
            'payment_id' => $payment?->getKey(),
            'provider' => InfinitePayGateway::NAME,
            'provider_event_id' => $notification->eventId,
            'event_type' => $notification->event->value,
            'provider_payment_id' => $this->cleanReference($notification->transactionNsu),
            'status' => $status,
            'failure_reason' => $failureReason,
            'payload_hash' => $notification->payloadHash,
            'amount_cents' => $notification->amountCents,
            'occurred_at' => $notification->occurredAt,
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(?Payment $payment, string $action, ?int $actorId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $payment?->getMorphClass(),
            'subject_id' => $payment?->getKey(),
            'metadata' => $metadata,
        ]);
    }

    private function webhookUrl(): string
    {
        $configured = trim((string) config('payments.infinitepay.webhook_url'));

        return $configured !== '' ? $configured : route('webhooks.infinitepay');
    }

    private function cleanReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
