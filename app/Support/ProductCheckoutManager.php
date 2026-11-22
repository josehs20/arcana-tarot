<?php

namespace App\Support;

use App\Enums\InfinitePayEvent;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Enums\PurchaseStatus;
use App\Models\AuditLog;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Purchase;
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
 * Compra de produtos da vitrine pelo Checkout Integrado da InfinitePay.
 *
 * O produto, o preço e a quantidade de créditos são lidos do catálogo no
 * servidor e congelados em `purchases`. Nenhum saldo é liberado sem
 * `payment_check` aprovado; a confirmação e o crédito na carteira acontecem na
 * mesma transação, com lock de linha e idempotência por `provider_event_id`.
 *
 * O webhook do provedor é compartilhado com as recargas: este manager devolve
 * `null` quando a referência não pertence a uma compra de produto, e o
 * `InfinitePayCheckoutManager` assume o pedido.
 */
final class ProductCheckoutManager
{
    public function __construct(
        private readonly CreditWalletManager $wallets,
        private readonly InfinitePayGateway $gateway,
    ) {}

    /**
     * O checkout de produtos exige o mesmo provedor configurado das recargas.
     */
    public function enabled(): bool
    {
        return config('payments.provider') === InfinitePayGateway::NAME
            && $this->gateway->isConfigured();
    }

    /**
     * Cria (ou reaproveita, pela chave idempotente) o pedido de compra e
     * devolve o link de checkout da InfinitePay.
     */
    public function startCheckout(User $client, Product $product, string $operationId): Purchase
    {
        if (! $client->is_active || ! $client->isClient()) {
            throw new DomainException('A compra de produtos é exclusiva de clientes ativos.');
        }

        if (! $product->is_active) {
            throw new DomainException('Este produto não está disponível no momento.');
        }

        if (! $this->enabled()) {
            throw new DomainException('O checkout InfinitePay não está configurado neste ambiente.');
        }

        $operationId = trim($operationId);

        if ($operationId === '') {
            throw new InvalidArgumentException('A operação de compra é obrigatória.');
        }

        $idempotencyKey = 'infinitepay_product:'.$client->getKey().':'.$operationId;
        $existing = $this->findExistingCheckout($client, $product, $idempotencyKey);

        if ($existing !== null) {
            return $existing->checkout_url === null && $existing->status === PurchaseStatus::Pending
                ? $this->attachCheckoutLink($existing)
                : $existing;
        }

        $purchase = DB::transaction(function () use ($client, $product, $idempotencyKey): Purchase {
            $creditUnitPriceCents = $product->credits_amount > 0
                ? intdiv($product->price_cents, $product->credits_amount)
                : null;

            $purchase = Purchase::query()->create([
                'user_id' => $client->getKey(),
                'product_id' => $product->getKey(),
                'product_title' => $product->title,
                'amount_cents' => $product->price_cents,
                'credits_amount' => $product->credits_amount,
                'credit_unit_price_cents' => $creditUnitPriceCents,
                'status' => PurchaseStatus::Pending,
                'provider' => InfinitePayGateway::NAME,
                'external_reference' => 'product_'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit($purchase, 'purchase.created', $client->getKey(), [
                'user_id' => $client->getKey(),
                'product_id' => $product->getKey(),
                'product_title' => $product->title,
                'amount_cents' => $product->price_cents,
                'credits_amount' => $product->credits_amount,
                'credit_unit_price_cents' => $creditUnitPriceCents,
                'external_reference' => $purchase->external_reference,
                'from' => null,
                'to' => PurchaseStatus::Pending->value,
            ]);

            return $purchase;
        });

        return $this->attachCheckoutLink($purchase);
    }

    /**
     * Notificação do webhook: localiza a compra pela referência externa.
     *
     * Retorna `null` quando a referência não é de um produto — o controller
     * então delega o evento ao checkout de créditos, e só ali um pedido
     * realmente desconhecido é registrado.
     */
    public function handleWebhook(WebhookNotification $notification): ?PaymentWebhookResult
    {
        $purchase = Purchase::query()
            ->where('provider', InfinitePayGateway::NAME)
            ->where('external_reference', $notification->orderNsu)
            ->first();

        if ($purchase === null) {
            return null;
        }

        $check = null;

        if ($notification->event->requiresServerConfirmation()) {
            $check = $this->verifyPayment($purchase, $notification);
        }

        return $this->apply($purchase->getKey(), $notification, $check);
    }

    /**
     * Reconciliação do retorno do navegador. Nunca libera crédito por confiança
     * na URL: consulta o provedor e usa a mesma transação do webhook.
     */
    public function reconcile(Purchase $purchase, ?string $transactionNsu, ?string $invoiceSlug): PaymentWebhookResult
    {
        if ($purchase->provider !== InfinitePayGateway::NAME) {
            throw new DomainException('Esta compra não pertence ao checkout InfinitePay.');
        }

        $purchase->refresh();

        if ($purchase->status !== PurchaseStatus::Pending) {
            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Ignored,
                purchase: $purchase,
                reason: 'purchase_already_'.$purchase->status->value,
            );
        }

        $transactionNsu = $this->cleanReference($transactionNsu) ?? $purchase->transaction_nsu;
        $invoiceSlug = $this->cleanReference($invoiceSlug) ?? $purchase->invoice_slug;

        $notification = new WebhookNotification(
            eventId: 'return_'.Str::ulid(),
            event: InfinitePayEvent::Paid,
            orderNsu: (string) $purchase->external_reference,
            amountCents: $purchase->amount_cents,
            payloadHash: hash('sha256', 'return|'.$purchase->getKey().'|'.($transactionNsu ?? '')),
            transactionNsu: $transactionNsu,
            invoiceSlug: $invoiceSlug,
            paidAmountCents: $purchase->amount_cents,
            occurredAt: now(),
        );

        $check = $transactionNsu === null || $invoiceSlug === null
            ? null
            : $this->gateway->checkPayment(new PaymentCheckRequest(
                orderNsu: (string) $purchase->external_reference,
                transactionNsu: $transactionNsu,
                invoiceSlug: $invoiceSlug,
            ));

        return $this->apply($purchase->getKey(), $notification, $check);
    }

    private function attachCheckoutLink(Purchase $purchase): Purchase
    {
        try {
            $link = $this->gateway->createCheckoutLink(new CheckoutLinkRequest(
                orderNsu: (string) $purchase->external_reference,
                amountCents: $purchase->amount_cents,
                description: $purchase->product_title.' - '.SiteContent::currentName(),
                redirectUrl: route('client.products.checkout.return'),
                webhookUrl: $this->webhookUrl(),
                customerName: $purchase->user?->name,
                customerEmail: $purchase->user?->email,
                customerPhone: $purchase->user?->phone,
                validUntil: now()->addMinutes(max(5, (int) config('payments.infinitepay.charge_ttl_minutes', 1440))),
            ));
        } catch (InfinitePayException $exception) {
            $this->markProviderFailure($purchase);

            Log::error('infinitepay.product_checkout_creation_failed', [
                'purchase_id' => $purchase->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new InfinitePayException(
                'Não foi possível gerar o link de pagamento agora. Tente novamente em instantes.',
                $exception,
            );
        }

        $purchase->forceFill([
            'checkout_url' => $link->url,
            'invoice_slug' => $link->invoiceSlug,
        ])->save();

        return $purchase->refresh();
    }

    private function verifyPayment(Purchase $purchase, WebhookNotification $notification): ?PaymentCheck
    {
        $transactionNsu = $this->cleanReference($notification->transactionNsu) ?? $purchase->transaction_nsu;
        $invoiceSlug = $this->cleanReference($notification->invoiceSlug) ?? $purchase->invoice_slug;

        if ($transactionNsu === null || $invoiceSlug === null) {
            return null;
        }

        return $this->gateway->checkPayment(new PaymentCheckRequest(
            orderNsu: (string) $purchase->external_reference,
            transactionNsu: $transactionNsu,
            invoiceSlug: $invoiceSlug,
        ));
    }

    private function findExistingCheckout(User $client, Product $product, string $idempotencyKey): ?Purchase
    {
        $existing = Purchase::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return null;
        }

        if (
            $existing->user_id !== $client->getKey()
            || $existing->product_id !== $product->getKey()
            || $existing->amount_cents !== $product->price_cents
            || $existing->credits_amount !== $product->credits_amount
            || $existing->provider !== InfinitePayGateway::NAME
        ) {
            throw new DomainException('Esta operação de compra já foi usada com outros dados.');
        }

        return $existing;
    }

    /**
     * Aplica a notificação em transação com lock na compra e no evento.
     */
    private function apply(int $purchaseId, WebhookNotification $notification, ?PaymentCheck $check): PaymentWebhookResult
    {
        return DB::transaction(function () use ($purchaseId, $notification, $check): PaymentWebhookResult {
            $purchase = Purchase::query()->lockForUpdate()->find($purchaseId);

            if ($purchase === null) {
                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Failed,
                    reason: 'unknown_order',
                );
            }

            $duplicate = PaymentWebhookEvent::query()
                ->where('provider_event_id', $notification->eventId)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Duplicate,
                    purchase: $duplicate->purchase,
                    event: $duplicate,
                    reason: 'duplicate_event',
                );
            }

            $from = $purchase->status;

            if ($from === PurchaseStatus::Cancelled || $from === PurchaseStatus::Refunded) {
                return $this->ignored($purchase, $notification, 'purchase_already_'.$from->value);
            }

            if ($from === PurchaseStatus::Paid) {
                return $notification->event === InfinitePayEvent::Refunded
                    ? $this->applyRefund($purchase, $notification)
                    : $this->ignored($purchase, $notification, 'purchase_already_paid');
            }

            if ($notification->event === InfinitePayEvent::Refunded) {
                return $this->ignored($purchase, $notification, 'refund_without_payment');
            }

            if ($notification->event === InfinitePayEvent::Canceled
                || $notification->event === InfinitePayEvent::PaymentFailed) {
                return $this->applyCancel($purchase, $notification);
            }

            if ($notification->event === InfinitePayEvent::Created) {
                return $this->ignored($purchase, $notification, 'awaiting_payment');
            }

            if ($notification->amountCents !== $purchase->amount_cents) {
                return $this->rejected($purchase, $notification, 'amount_mismatch');
            }

            if ($check === null) {
                return $this->rejected($purchase, $notification, 'missing_reference');
            }

            if (! $check->confirmed()) {
                return $this->rejected($purchase, $notification, 'not_paid_confirmed', [
                    'check_success' => $check->success,
                    'check_paid' => $check->paid,
                ]);
            }

            if ($check->amountCents === null) {
                return $this->rejected($purchase, $notification, 'amount_unavailable');
            }

            if ($check->amountCents !== $purchase->amount_cents) {
                return $this->rejected($purchase, $notification, 'amount_mismatch', [
                    'received_amount_cents' => $check->amountCents,
                ]);
            }

            return $this->confirm($purchase, $notification, $check, $from);
        });
    }

    private function confirm(
        Purchase $purchase,
        WebhookNotification $notification,
        PaymentCheck $check,
        PurchaseStatus $from,
    ): PaymentWebhookResult {
        $this->wallets->credit(
            $purchase->user,
            $purchase->amount_cents,
            'product_purchase:'.$purchase->getKey(),
            $purchase,
            'Compra: '.$purchase->product_title,
        );

        $purchase->forceFill([
            'status' => PurchaseStatus::Paid,
            'failure_reason' => null,
            'paid_at' => now(),
            'transaction_nsu' => $this->cleanReference($notification->transactionNsu) ?? $purchase->transaction_nsu,
            'invoice_slug' => $this->cleanReference($notification->invoiceSlug) ?? $purchase->invoice_slug,
        ])->save();

        $this->audit($purchase, 'purchase.paid', null, [
            'user_id' => $purchase->user_id,
            'provider_event_id' => $notification->eventId,
            'product_id' => $purchase->product_id,
            'product_title' => $purchase->product_title,
            'amount_cents' => $purchase->amount_cents,
            'credits_amount' => $purchase->credits_amount,
            'capture_method' => $check->captureMethod ?? $notification->captureMethod,
            'from' => $from->value,
            'to' => PurchaseStatus::Paid->value,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            purchase: $purchase,
            event: $this->storeEvent($purchase, $notification, PaymentEventStatus::Processed),
        );
    }

    private function applyCancel(Purchase $purchase, WebhookNotification $notification): PaymentWebhookResult
    {
        $reason = $notification->event === InfinitePayEvent::Canceled ? 'provider_canceled' : 'provider_declined';

        $purchase->forceFill([
            'status' => PurchaseStatus::Cancelled,
            'failure_reason' => $reason,
        ])->save();

        $this->audit($purchase, 'purchase.cancelled', null, [
            'user_id' => $purchase->user_id,
            'provider_event_id' => $notification->eventId,
            'amount_cents' => $purchase->amount_cents,
            'credits_amount' => $purchase->credits_amount,
            'from' => PurchaseStatus::Pending->value,
            'to' => PurchaseStatus::Cancelled->value,
            'reason' => $reason,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            purchase: $purchase,
            event: $this->storeEvent($purchase, $notification, PaymentEventStatus::Processed),
        );
    }

    /**
     * Estorno de compra já paga. Os créditos não são removidos
     * automaticamente (podem ter sido consumidos): a compra fica marcada como
     * estornada e a auditoria sinaliza análise manual.
     */
    private function applyRefund(Purchase $purchase, WebhookNotification $notification): PaymentWebhookResult
    {
        $purchase->forceFill([
            'status' => PurchaseStatus::Refunded,
            'refunded_at' => now(),
        ])->save();

        $this->audit($purchase, 'purchase.refunded', null, [
            'user_id' => $purchase->user_id,
            'provider_event_id' => $notification->eventId,
            'amount_cents' => $purchase->amount_cents,
            'credits_amount' => $purchase->credits_amount,
            'transaction_nsu' => $this->cleanReference($notification->transactionNsu) ?? $purchase->transaction_nsu,
            'from' => PurchaseStatus::Paid->value,
            'to' => PurchaseStatus::Refunded->value,
            'manual_review_required' => true,
        ]);

        Log::warning('infinitepay.purchase_refunded', [
            'purchase_id' => $purchase->getKey(),
            'user_id' => $purchase->user_id,
            'amount_cents' => $purchase->amount_cents,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Processed,
            purchase: $purchase,
            event: $this->storeEvent($purchase, $notification, PaymentEventStatus::Processed),
        );
    }

    private function ignored(Purchase $purchase, WebhookNotification $notification, string $reason): PaymentWebhookResult
    {
        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Ignored,
            purchase: $purchase,
            event: $this->storeEvent($purchase, $notification, PaymentEventStatus::Ignored, $reason),
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function rejected(
        Purchase $purchase,
        WebhookNotification $notification,
        string $reason,
        array $metadata = [],
    ): PaymentWebhookResult {
        $record = $this->storeEvent($purchase, $notification, PaymentEventStatus::Failed, $reason);

        $this->audit($purchase, 'purchase.webhook_rejected', null, array_merge([
            'provider_event_id' => $notification->eventId,
            'reason' => $reason,
            'order_nsu' => $notification->orderNsu,
            'expected_amount_cents' => $purchase->amount_cents,
            'received_amount_cents' => $notification->amountCents,
            'payload_hash' => $notification->payloadHash,
        ], $metadata));

        Log::warning('infinitepay.product_webhook_rejected', [
            'purchase_id' => $purchase->getKey(),
            'reason' => $reason,
            'order_nsu' => $notification->orderNsu,
        ]);

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Failed,
            purchase: $purchase,
            event: $record,
            reason: $reason,
        );
    }

    private function markProviderFailure(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase): void {
            $locked = Purchase::query()->lockForUpdate()->find($purchase->getKey());

            if ($locked === null || $locked->status !== PurchaseStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => PurchaseStatus::Cancelled,
                'failure_reason' => 'provider_error',
            ])->save();

            $this->audit($locked, 'purchase.provider_failed', null, [
                'user_id' => $locked->user_id,
                'amount_cents' => $locked->amount_cents,
                'from' => PurchaseStatus::Pending->value,
                'to' => PurchaseStatus::Cancelled->value,
                'reason' => 'provider_error',
            ]);
        });
    }

    private function storeEvent(
        ?Purchase $purchase,
        WebhookNotification $notification,
        PaymentEventStatus $status,
        ?string $failureReason = null,
    ): PaymentWebhookEvent {
        return PaymentWebhookEvent::query()->create([
            'payment_id' => null,
            'purchase_id' => $purchase?->getKey(),
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
    private function audit(?Purchase $purchase, string $action, ?int $actorId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $purchase?->getMorphClass(),
            'subject_id' => $purchase?->getKey(),
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
