<?php

namespace App\Enums;

/**
 * Estado de uma compra de produto.
 *
 * O status só é alterado pelo `ProductCheckoutManager` depois de uma
 * confirmação server-side do provedor. Não existe transição administrativa:
 * a tela de vendas é somente leitura.
 */
enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Paid => 'Pago',
            self::Cancelled => 'Cancelado',
            self::Refunded => 'Estornado',
        };
    }

    /**
     * Chave usada pelo `<x-ui.status-badge>` no painel e na área do cliente.
     */
    public function statusKey(): string
    {
        return $this->value;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
