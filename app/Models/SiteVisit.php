<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\SiteVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita (sessão) de um visitante ao site.
 *
 * O mesmo `visitor_id` (cookie aleatório) pode ter várias visitas ao longo do
 * tempo: uma nova linha é criada após o período de inatividade configurado.
 * `ended_at` guarda o último sinal de atividade, não o instante em que o
 * navegador fechou — o servidor não tem como observar esse evento.
 */
#[Fillable([
    'visitor_id',
    'user_id',
    'ip_hash',
    'browser',
    'platform',
    'device_type',
    'referrer_host',
    'entry_path',
    'last_path',
    'page_views',
    'started_at',
    'last_activity_at',
    'ended_at',
])]
class SiteVisit extends Model
{
    /** @use HasFactory<SiteVisitFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
            'page_views' => 'integer',
        ];
    }

    public static function hashIp(?string $ip): ?string
    {
        if (! is_string($ip) || $ip === '') {
            return null;
        }

        /*
         * HMAC com a chave da aplicação: o mesmo IP gera o mesmo pseudônimo
         * (útil para detectar abuso), mas o hash não pode ser revertido por
         * força bruta a partir do espaço de IPv4 sem a chave.
         */
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeAnonymous(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Visita ainda em andamento: aberta e com sinal de atividade recente.
     * Sem heartbeat (aba oculta ou navegador fechado), a visita deixa de ser
     * exibida como ativa e o fim passa a ser o último sinal registrado.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null
            && $this->last_activity_at !== null
            && $this->last_activity_at->gte(now()->subMinutes($this->onlineWindowMinutes()));
    }

    /**
     * Tempo no site em segundos. Para visitas em andamento usa o último
     * heartbeat registrado pelo servidor.
     */
    public function durationSeconds(): int
    {
        $end = $this->ended_at ?? $this->last_activity_at;

        if ($this->started_at === null || $end === null) {
            return 0;
        }

        return max(0, $end->getTimestamp() - $this->started_at->getTimestamp());
    }

    public function durationLabel(): string
    {
        return self::formatDuration($this->durationSeconds());
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dmin', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dmin %02ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }

    /**
     * Fim exibido na listagem: visitas ativas não têm fim definitivo.
     */
    public function endedLabel(): string
    {
        if ($this->isActive()) {
            return 'Em andamento';
        }

        return ($this->ended_at ?? $this->last_activity_at)?->format('d/m/Y H:i') ?? '—';
    }

    public function visitorLabel(): string
    {
        return $this->user?->name ?? 'Visitante anônimo';
    }

    /**
     * Código curto do visitante anônimo. Não é reversível para IP ou qualquer
     * outro identificador — serve apenas para reconhecer o mesmo navegador.
     */
    public function visitorCode(): string
    {
        return strtoupper(substr($this->visitor_id, 0, 8));
    }

    public function audienceLabel(): string
    {
        if ($this->user === null) {
            return 'Sem cadastro';
        }

        return match ($this->user->role) {
            UserRole::Admin => 'Admin',
            UserRole::Attendant => 'Atendente',
            UserRole::Client => 'Cliente',
        };
    }

    public function audienceBadgeVariant(): string
    {
        if ($this->user === null) {
            return 'secondary';
        }

        return match ($this->user->role) {
            UserRole::Admin => 'info',
            UserRole::Attendant => 'gold',
            UserRole::Client => 'success',
        };
    }

    public function deviceLabel(): ?string
    {
        return match ($this->device_type) {
            'mobile' => 'Celular',
            'tablet' => 'Tablet',
            'desktop' => 'Desktop',
            default => null,
        };
    }

    private function onlineWindowMinutes(): int
    {
        return max(1, (int) config('access_log.online_window_minutes', 5));
    }
}
