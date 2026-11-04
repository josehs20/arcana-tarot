<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Confirmed => 'Confirmado',
            self::Expired => 'Expirado',
            self::Failed => 'Recusado',
            self::Refunded => 'Estornado',
        };
    }
}
