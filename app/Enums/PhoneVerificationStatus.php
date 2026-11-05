<?php

namespace App\Enums;

/**
 * Situação de uma verificação de celular iniciada no cadastro.
 */
enum PhoneVerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando código',
            self::Approved => 'Confirmado',
            self::Expired => 'Expirado',
            self::Failed => 'Falhou',
        };
    }
}
