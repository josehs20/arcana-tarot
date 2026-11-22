<?php

namespace App\Support;

use App\Models\SiteVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicadores da tela "Acessos".
 *
 * Todos os números respeitam o período e o filtro de tipo escolhidos na tela.
 * "Únicos" conta navegadores pelo cookie anônimo, não pessoas: o sistema não
 * tem como distinguir dois visitantes que compartilham o mesmo computador.
 */
final class SiteVisitMetrics
{
    /**
     * @return array{visits: int, unique: int, registered: int, anonymous: int, page_views: int, today: int, online: int, average_seconds: int}
     */
    public static function summary(CarbonImmutable $start, ?string $audience = null): array
    {
        $base = SiteVisit::query()->where('started_at', '>=', $start);
        self::applyAudience($base, $audience);

        $totalSeconds = 0;
        $measured = 0;

        foreach ((clone $base)->select(['id', 'started_at', 'last_activity_at', 'ended_at'])->cursor() as $visit) {
            $totalSeconds += $visit->durationSeconds();
            $measured++;
        }

        return [
            'visits' => (clone $base)->count(),
            'unique' => (clone $base)->distinct()->count('visitor_id'),
            'registered' => (clone $base)->whereNotNull('user_id')->count(),
            'anonymous' => (clone $base)->whereNull('user_id')->count(),
            'page_views' => (int) (clone $base)->sum('page_views'),
            'today' => (clone $base)->where('started_at', '>=', now()->startOfDay())->count(),
            'online' => (clone $base)
                ->whereNull('ended_at')
                ->where('last_activity_at', '>=', now()->subMinutes(
                    max(1, (int) config('access_log.online_window_minutes', 5)),
                ))
                ->count(),
            'average_seconds' => $measured > 0 ? intdiv($totalSeconds, $measured) : 0,
        ];
    }

    private static function applyAudience(Builder $query, ?string $audience): void
    {
        if ($audience === 'registered') {
            $query->registered();
        }

        if ($audience === 'anonymous') {
            $query->anonymous();
        }
    }
}
