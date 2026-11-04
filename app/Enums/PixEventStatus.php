<?php

namespace App\Enums;

/**
 * Status normalizado devolvido pelo adaptador do provedor PIX.
 *
 * O provedor pode usar nomes diferentes ("approved", "paid", "RECEIVED"...);
 * o adaptador traduz para este enum antes de entregar o evento ao domínio.
 */
enum PixEventStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Declined = 'declined';
    case Expired = 'expired';
}
