<?php

namespace App\Models;

use App\Enums\AttendantAvailability;
use App\Enums\AttendantRequestSound;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use Carbon\CarbonInterface;
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

#[Fillable(['user_id', 'display_name', 'bio', 'photo_path', 'specialties', 'offered_modalities', 'request_sound', 'request_sound_path', 'commission_rate_bps', 'is_published', 'availability', 'last_seen_at'])]
#[Hidden(['commission_rate_bps'])]
class AttendantProfile extends Model
{
    /** @use HasFactory<AttendantProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'offered_modalities' => 'array',
            'request_sound' => AttendantRequestSound::class,
            'commission_rate_bps' => 'integer',
            'is_published' => 'boolean',
            'availability' => AttendantAvailability::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Registra presença recente sem gerar uma escrita a cada requisição.
     */
    public function markSeen(): void
    {
        if (! $this->exists) {
            return;
        }

        $now = now();
        $touchAfter = $now->copy()->subSeconds((int) config('consultation.last_seen_touch_seconds', 30));

        if ($this->last_seen_at !== null && $this->last_seen_at->greaterThan($touchAfter)) {
            return;
        }

        $this->forceFill(['last_seen_at' => $now])->saveQuietly();
    }

    /**
     * Presença suficientemente recente para manter o atendente online ao fim
     * de uma consulta. Sem batimento contínuo, é a única evidência disponível.
     */
    public function hasRecentPresence(?CarbonInterface $at = null): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $limit = ($at ?? now())->copy()->subMinutes((int) config('consultation.presence_timeout_minutes', 15));

        return $this->last_seen_at->greaterThanOrEqualTo($limit);
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
     * Modalidades efetivamente oferecidas pelo profissional.
     *
     * Perfis antigos (ou criados sem configuração) oferecem apenas texto:
     * áudio e vídeo nunca são presumidos sem habilitação explícita.
     *
     * @return array<int, ConsultationModality>
     */
    public function offeredModalities(): array
    {
        $values = $this->offered_modalities;

        if (! is_array($values) || $values === []) {
            $values = [ConsultationModality::Text->value];
        }

        $modalities = [];

        foreach ($values as $value) {
            $modality = $value instanceof ConsultationModality
                ? $value
                : ConsultationModality::tryFrom((string) $value);

            if ($modality !== null && ! in_array($modality, $modalities, true)) {
                $modalities[] = $modality;
            }
        }

        if ($modalities === []) {
            $modalities[] = ConsultationModality::Text;
        }

        return $modalities;
    }

    /**
     * @return array<int, string>
     */
    public function offeredModalityValues(): array
    {
        return array_map(
            static fn (ConsultationModality $modality): string => $modality->value,
            $this->offeredModalities(),
        );
    }

    public function offersModality(ConsultationModality|string $modality): bool
    {
        $modality = $modality instanceof ConsultationModality
            ? $modality
            : ConsultationModality::tryFrom($modality);

        return $modality !== null && in_array($modality, $this->offeredModalities(), true);
    }

    /**
     * Toque do painel para novas solicitações.
     *
     * O cast pode devolver `null` em registros antigos ou importados fora do
     * formulário; nesse caso a sirene continua sendo o padrão seguro.
     */
    public function requestSound(): AttendantRequestSound
    {
        return $this->request_sound instanceof AttendantRequestSound
            ? $this->request_sound
            : AttendantRequestSound::default();
    }

    /**
     * Toque efetivamente reproduzido.
     *
     * Se o perfil aponta para um MP3 personalizado que não existe mais no disco
     * (arquivo removido fora do painel), a sirene histórica é usada como
     * fallback para o atendente não ficar sem aviso sonoro.
     */
    public function effectiveRequestSound(): AttendantRequestSound
    {
        $sound = $this->requestSound();

        return $sound->isCustom() && $this->requestSoundUrl() === null
            ? AttendantRequestSound::default()
            : $sound;
    }

    /**
     * URL pública do MP3 personalizado, quando o arquivo existe no disco.
     */
    public function requestSoundUrl(): ?string
    {
        if (blank($this->request_sound_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->request_sound_path) ? $disk->url($this->request_sound_path) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function defaultModalityValues(): array
    {
        return [ConsultationModality::Text->value];
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
