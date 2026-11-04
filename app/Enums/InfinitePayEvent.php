<?php

namespace App\Enums;

/**
 * Eventos normalizados do Checkout Integrado da InfinitePay.
 *
 * A InfinitePay não envia um campo de evento tipado em todas as notificações:
 * o corpo de um pagamento aprovado pode chegar apenas com `order_nsu`,
 * `transaction_nsu`, `invoice_slug`, `amount` e `paid_amount`. Por isso
 * `fromWebhook()` normaliza variações conhecidas e trata o corpo "sem evento"
 * como pagamento aprovado, sempre exigindo confirmação via `payment_check`.
 */
enum InfinitePayEvent: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Settled = 'settled';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case PaymentFailed = 'payment_failed';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhook(array $payload): self
    {
        foreach (['event', 'event_type', 'type', 'status'] as $key) {
            $value = $payload[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $event = self::normalize($value);

            if ($event !== null) {
                return $event;
            }
        }

        return self::Paid;
    }

    public static function normalize(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'created', 'waiting_payment', 'pending', 'link_created' => self::Created,
            'paid', 'approved', 'captured', 'succeeded' => self::Paid,
            'settled', 'completed' => self::Settled,
            'canceled', 'cancelled', 'expired' => self::Canceled,
            'refunded', 'refund', 'chargeback' => self::Refunded,
            'payment_failed', 'failed', 'declined', 'refused' => self::PaymentFailed,
            default => null,
        };
    }

    /**
     * Eventos que só podem liberar créditos depois de uma consulta server-side.
     */
    public function requiresServerConfirmation(): bool
    {
        return $this === self::Paid || $this === self::Settled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Link criado',
            self::Paid => 'Pago',
            self::Settled => 'Liquidado',
            self::Canceled => 'Cancelado',
            self::Refunded => 'Estornado',
            self::PaymentFailed => 'Recusado',
        };
    }
}
