<?php

namespace App\Models;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationStatus;
use Database\Factories\AttendantProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'display_name', 'bio', 'photo_path', 'specialties', 'commission_rate_bps', 'is_published', 'availability'])]
#[Hidden(['commission_rate_bps'])]
class AttendantProfile extends Model
{
    /** @use HasFactory<AttendantProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'commission_rate_bps' => 'integer',
            'is_published' => 'boolean',
            'availability' => AttendantAvailability::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Consulta ativa do atendente. Usada para derivar o status "ocupado".
     */
    public function activeConsultation(): HasOne
    {
        return $this->hasOne(Consultation::class, 'attendant_id', 'user_id')
            ->where('status', ConsultationStatus::Active->value);
    }

    /**
     * Perfis visíveis no site: publicados e com usuário ativo.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereHas('user', fn (Builder $user) => $user->where('is_active', true));
    }

    /**
     * Perfis que aceitam novas solicitações (online, mesmo que em consulta).
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->published()
            ->where('availability', AttendantAvailability::Online->value);
    }

    /**
     * Online primeiro; depois ordem alfabética do nome público.
     */
    public function scopeOrderForDirectory(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when availability = ? then 0 else 1 end', [AttendantAvailability::Online->value])
            ->orderBy('display_name');
    }

    /**
     * Status público: online, busy (em consulta) ou offline.
     */
    public function publicStatus(): string
    {
        $busy = (bool) ($this->active_consultation_exists ?? false);

        if (! $busy && $this->relationLoaded('activeConsultation')) {
            $busy = $this->activeConsultation !== null;
        }

        if ($busy) {
            return 'busy';
        }

        return $this->availability === AttendantAvailability::Online ? 'online' : 'offline';
    }

    /**
     * URL pública da foto, quando existir no disco público.
     */
    public function photoUrl(): ?string
    {
        if (blank($this->photo_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->photo_path) ? $disk->url($this->photo_path) : null;
    }

    /**
     * Iniciais usadas no avatar quando não há foto.
     */
    public function initials(): string
    {
        $initials = Str::of($this->display_name)
            ->squish()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '★';
    }
}
