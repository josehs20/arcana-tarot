<?php

namespace App\Support;

use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Enums\PixEventStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Support\Payments\Contracts\PixProvider;
use App\Support\Payments\Data\PixChargeRequest;
use App\Support\Payments\Data\PixWebhookEvent;
use App\Support\Payments\Exceptions\PixProviderException;
use App\Support\Payments\PaymentWebhookResult;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Criação e transição de pedidos de recarga PIX.
 *
 * Nenhum método público aceita status vindo do navegador: a confirmação só
 * existe em handleWebhook(), depois que o adaptador do provedor validou a
 * autenticidade da notificação. O crédito é lançado no livro-razão na mesma
 * transação da mudança de status e usa uma chave idempotente por pagamento.
 */
final class PaymentManager
{
    public function __construct(
        private CreditWalletManager $wallets,
        private ConsultationPricing $pricing,
        private PaymentLimits $limits,
        private PixProvider $provider,
    ) {}

    public function startRecharge(User $client, int $amountCents, string $operationId): Payment
    {
        if (! $client->is_active || ! $client->isClient()) {
            throw new DomainException('A recarga é exclusiva de clientes ativos.');
        }

        if (! $this->provider->isAvailable()) {
            throw new PixProviderException('O provedor PIX não está configurado neste ambiente.');
        }

        $operationId = trim($operationId);

        if ($operationId === '') {
            throw new InvalidArgumentException('A operação de recarga é obrigatória.');
        }

        $unitPriceCents = $this->pricing->creditUnitPriceCents();

        if ($unitPriceCents === null) {
            throw new DomainException('O valor do crédito ainda não foi configurado pela administração.');
        }

        $this->validateAmount($amountCents, $unitPriceCents);

        $credits = intdiv($amountCents, $unitPriceCents);
        $idempotencyKey = 'pix_recharge:'.$client->getKey().':'.$operationId;

        $existing = $this->findExistingRecharge($client, $amountCents, $credits, $idempotencyKey);

        if ($existing !== null) {
            if ($existing->provider_payment_id === null && $existing->status === PaymentStatus::Pending) {
                return $this->attachCharge($existing);
            }

            return $existing;
        }

        $payment = DB::transaction(function () use ($client, $amountCents, $credits, $unitPriceCents, $idempotencyKey): Payment {
            $payment = Payment::query()->create([
                'user_id' => $client->getKey(),
                'provider' => $this->provider->name(),
                'external_reference' => 'recharge_'.Str::ulid(),
                'amount_cents' => $amountCents,
                'credits' => $credits,
                'credit_unit_price_cents' => $unitPriceCents,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit($payment, 'payment.created', $client->getKey(), [
                'user_id' => $client->getKey(),
                'provider' => $this->provider->name(),
                'amount_cents' => $amountCents,
                'credits' => $credits,
                'credit_unit_price_cents' => $unitPriceCents,
                'external_reference' => $payment->external_reference,
                'from' => null,
                'to' => PaymentStatus::Pending->value,
            ]);

            return $payment;
        });

        return $this->attachCharge($payment);
    }

    public function handleWebhook(PixWebhookEvent $event): PaymentWebhookResult
    {
        return DB::transaction(function () use ($event): PaymentWebhookResult {
            $existingEvent = PaymentWebhookEvent::query()
                ->where('provider_event_id', $event->eventId)
                ->lockForUpdate()
                ->first();

            if ($existingEvent !== null) {
                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Duplicate,
                    payment: $existingEvent->payment,
                    event: $existingEvent,
                    reason: 'duplicate_event',
                );
            }

            $payment = Payment::query()
                ->where('provider', $this->provider->name())
                ->where('provider_payment_id', $event->providerPaymentId)
                ->lockForUpdate()
                ->first();

            // Rechecagem após o lock: outra entrega simultânea do mesmo evento
            // pode ter sido concluída enquanto esta transação esperava a linha.
            $existingEvent = PaymentWebhookEvent::query()
                ->where('provider_event_id', $event->eventId)
                ->lockForUpdate()
                ->first();

            if ($existingEvent !== null) {
                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Duplicate,
                    payment: $existingEvent->payment,
                    event: $existingEvent,
                    reason: 'duplicate_event',
                );
            }

            if ($payment === null) {
                $record = $this->storeEvent(null, $event, PaymentEventStatus::Failed, 'unknown_payment');

                $this->audit(null, 'payment.webhook_rejected', null, [
                    'provider' => $this->provider->name(),
                    'provider_event_id' => $event->eventId,
                    'provider_payment_id' => $event->providerPaymentId,
                    'reason' => 'unknown_payment',
                    'payload_hash' => $event->payloadHash,
                ]);

                Log::warning('pix.webhook_unknown_payment', [
                    'provider' => $this->provider->name(),
                    'provider_event_id' => $event->eventId,
                ]);

                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Failed,
                    event: $record,
                    reason: 'unknown_payment',
                );
            }

            if ($event->amountCents !== $payment->amount_cents) {
                $record = $this->storeEvent($payment, $event, PaymentEventStatus::Failed, 'amount_mismatch');

                $this->audit($payment, 'payment.webhook_rejected', null, [
                    'provider_event_id' => $event->eventId,
                    'reason' => 'amount_mismatch',
                    'expected_amount_cents' => $payment->amount_cents,
                    'received_amount_cents' => $event->amountCents,
                    'payload_hash' => $event->payloadHash,
                ]);

                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Failed,
                    payment: $payment,
                    event: $record,
                    reason: 'amount_mismatch',
                );
            }

            if ($payment->status !== PaymentStatus::Pending) {
                $record = $this->storeEvent(
                    $payment,
                    $event,
                    PaymentEventStatus::Ignored,
                    'payment_already_'.$payment->status->value,
                );

                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Ignored,
                    payment: $payment,
                    event: $record,
                    reason: 'payment_already_'.$payment->status->value,
                );
            }

            if ($event->status === PixEventStatus::Pending) {
                $record = $this->storeEvent($payment, $event, PaymentEventStatus::Ignored, 'provider_pending');

                return new PaymentWebhookResult(
                    outcome: PaymentWebhookOutcome::Ignored,
                    payment: $payment,
                    event: $record,
                    reason: 'provider_pending',
                );
            }

            $record = $this->applyTransition($payment, $event);

            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Processed,
                payment: $payment->refresh(),
                event: $record,
            );
        });
    }

    private function applyTransition(Payment $payment, PixWebhookEvent $event): PaymentWebhookEvent
    {
        $from = $payment->status;

        if ($event->status === PixEventStatus::Paid) {
            $this->wallets->credit(
                $payment->user,
                $payment->amount_cents,
                'payment:'.$payment->getKey(),
                $payment,
            );

            $payment->forceFill([
                'status' => PaymentStatus::Confirmed,
                'raw_status' => 'approved',
                'failure_reason' => null,
                'paid_at' => now(),
            ])->save();

            $this->audit($payment, 'payment.confirmed', null, [
                'user_id' => $payment->user_id,
                'provider_event_id' => $event->eventId,
                'amount_cents' => $payment->amount_cents,
                'credits' => $payment->credits,
                'from' => $from->value,
                'to' => PaymentStatus::Confirmed->value,
            ]);

            return $this->storeEvent($payment, $event, PaymentEventStatus::Processed);
        }

        if ($event->status === PixEventStatus::Declined) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'raw_status' => 'declined',
                'failure_reason' => 'provider_declined',
            ])->save();

            $this->audit($payment, 'payment.declined', null, [
                'user_id' => $payment->user_id,
                'provider_event_id' => $event->eventId,
                'amount_cents' => $payment->amount_cents,
                'from' => $from->value,
                'to' => PaymentStatus::Failed->value,
                'reason' => 'provider_declined',
            ]);

            return $this->storeEvent($payment, $event, PaymentEventStatus::Processed);
        }

        if ($event->status === PixEventStatus::Expired) {
            $payment->forceFill([
                'status' => PaymentStatus::Expired,
                'raw_status' => 'expired',
                'failure_reason' => 'provider_expired',
            ])->save();

            $this->audit($payment, 'payment.expired', null, [
                'user_id' => $payment->user_id,
                'provider_event_id' => $event->eventId,
                'amount_cents' => $payment->amount_cents,
                'from' => $from->value,
                'to' => PaymentStatus::Expired->value,
                'reason' => 'provider_expired',
            ]);

            return $this->storeEvent($payment, $event, PaymentEventStatus::Processed);
        }

        throw new DomainException('Transição de pagamento não suportada.');
    }

    private function attachCharge(Payment $payment): Payment
    {
        $expiresAt = now()->addMinutes(max(1, (int) config('payments.sandbox.charge_ttl_minutes', 30)));

        try {
            $charge = $this->provider->createCharge(new PixChargeRequest(
                externalReference: (string) $payment->external_reference,
                amountCents: $payment->amount_cents,
                description: 'Recarga '.SiteContent::currentName().' — '.$payment->credits.' créditos',
                expiresAt: $expiresAt,
            ));
        } catch (Throwable $exception) {
            $this->markProviderFailure($payment);

            Log::error('pix.charge_creation_failed', [
                'payment_id' => $payment->getKey(),
                'provider' => $this->provider->name(),
                'exception' => $exception::class,
            ]);

            throw new PixProviderException(
                'Não foi possível gerar a cobrança PIX de teste. Tente novamente mais tarde.',
                previous: $exception,
            );
        }

        $payment->forceFill([
            'provider_payment_id' => $charge->providerPaymentId,
            'pix_copy_paste' => $charge->copyPaste,
            'expires_at' => $charge->expiresAt,
            'raw_status' => $charge->rawStatus,
        ])->save();

        return $payment->refresh();
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

    private function findExistingRecharge(
        User $client,
        int $amountCents,
        int $credits,
        string $idempotencyKey,
    ): ?Payment {
        $existing = Payment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return null;
        }

        if (
            $existing->user_id !== $client->getKey()
            || $existing->amount_cents !== $amountCents
            || $existing->credits !== $credits
            || $existing->provider !== $this->provider->name()
        ) {
            throw new DomainException('Esta operação de recarga já foi usada com outros dados.');
        }

        return $existing;
    }

    private function storeEvent(
        ?Payment $payment,
        PixWebhookEvent $event,
        PaymentEventStatus $status,
        ?string $failureReason = null,
    ): PaymentWebhookEvent {
        return PaymentWebhookEvent::query()->create([
            'payment_id' => $payment?->getKey(),
            'provider' => $this->provider->name(),
            'provider_event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'provider_payment_id' => $event->providerPaymentId,
            'status' => $status,
            'failure_reason' => $failureReason,
            'payload_hash' => $event->payloadHash,
            'amount_cents' => $event->amountCents,
            'occurred_at' => $event->occurredAt,
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

    private function validateAmount(int $amountCents, int $unitPriceCents): void
    {
        $minimum = $this->limits->minimumCents();
        $maximum = $this->limits->maximumCents();

        if ($amountCents < $minimum || $amountCents > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'O valor da recarga deve estar entre %s e %s.',
                Money::format($minimum),
                Money::format($maximum),
            ));
        }

        if ($amountCents % $unitPriceCents !== 0) {
            throw new InvalidArgumentException(
                'O valor da recarga deve ser múltiplo de '.Money::format($unitPriceCents).'.',
            );
        }

        if (intdiv($amountCents, $unitPriceCents) < 1) {
            throw new InvalidArgumentException('A recarga deve conceder pelo menos um crédito.');
        }
    }
}
