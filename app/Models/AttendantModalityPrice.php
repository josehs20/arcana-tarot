<?php

namespace App\Models;

use App\Enums\ConsultationModality;
use App\Support\Money;
use Database\Factories\AttendantModalityPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preço por minuto de uma modalidade em um perfil profissional.
 *
 * É a única fonte do preço de novas consultas: a administração define o valor
 * em centavos por atendente/modalidade e o servidor congela esse valor em
 * `consultations.price_per_minute_cents` no aceite. Alterações posteriores
 * valem apenas para novas consultas.
 *
 * @property int $id
 * @property int $attendant_profile_id
 * @property ConsultationModality $modality
 * @property int $price_per_minute_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AttendantProfile $attendantProfile
 */
#[Fillable(['attendant_profile_id', 'modality', 'price_per_minute_cents'])]
class AttendantModalityPrice extends Model
{
    /** @use HasFactory<AttendantModalityPriceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'modality' => ConsultationModality::class,
            'price_per_minute_cents' => 'integer',
        ];
    }

    public function attendantProfile(): BelongsTo
    {
        return $this->belongsTo(AttendantProfile::class);
    }

    /**
     * Ex.: "R$ 1,20".
     */
    public function priceLabel(): string
    {
        return Money::format($this->price_per_minute_cents);
    }
}
