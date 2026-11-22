<?php

namespace App\Http\Middleware;

use App\Models\SiteVisit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra visitas ao site para a tela "Acessos" do painel administrativo.
 *
 * Regras de coleta (privacidade primeiro):
 * - só páginas HTML 2xx em GET, sem AJAX, API, webhooks, health check, assets
 *   nem o próprio painel administrativo;
 * - o visitante é identificado por um cookie aleatório (UUID), não pelo IP;
 * - o IP é guardado apenas como HMAC-SHA256 (pseudônimo), nunca em texto claro;
 * - robôs são descartados para não inflar os números;
 * - uma nova visita começa depois do período de inatividade configurado.
 *
 * Falhas de coleta nunca derrubam a resposta: a navegação do visitante tem
 * prioridade sobre a estatística.
 */
class TrackSiteVisit
{
    /**
     * Assinaturas conservadoras de crawlers, monitores e clientes HTTP.
     */
    private const BOT_PATTERN = '/bot|crawler|spider|crawling|slurp|facebookexternalhit|whatsapp|telegrambot|headlesschrome|uptime|pingdom|python-requests|go-http-client|curl|wget/i';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldTrack($request, $response)) {
            return $response;
        }

        try {
            $this->track($request);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! config('access_log.enabled', true)) {
            return false;
        }

        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }

        if ($request->ajax() || $request->expectsJson()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        return ! $request->is((array) config('access_log.excluded_paths', []));
    }

    private function track(Request $request): void
    {
        $userAgent = (string) $request->userAgent();

        if (config('access_log.ignore_bots', true) && preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return;
        }

        $now = now();
        $visitorId = $this->visitorId($request);
        $userId = $request->user()?->getKey();
        $path = $this->path($request);
        $inactivityMinutes = max(1, (int) config('access_log.inactivity_minutes', 30));

        $visit = SiteVisit::query()
            ->where('visitor_id', $visitorId)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        /*
         * Sem atividade recente, a sessão anterior é encerrada no último sinal
         * registrado e uma nova visita é aberta para a navegação atual.
         */
        if ($visit !== null && $visit->last_activity_at->lt($now->copy()->subMinutes($inactivityMinutes))) {
            $this->close($visit);
            $visit = null;
        }

        /*
         * Troca de conta no mesmo navegador: a visita anterior continua
         * atribuída a quem estava conectado e a nova nasce com o novo usuário.
         */
        if (
            $visit !== null
            && $visit->user_id !== null
            && $userId !== null
            && (int) $visit->user_id !== (int) $userId
        ) {
            $this->close($visit);
            $visit = null;
        }

        if ($visit === null) {
            /*
             * Qualquer visita órfã que tenha sobrado de um encerramento
             * interrompido é fechada antes da nova linha, mantendo apenas uma
             * visita aberta por navegador.
             */
            SiteVisit::query()
                ->where('visitor_id', $visitorId)
                ->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('last_activity_at')]);

            SiteVisit::create([
                'visitor_id' => $visitorId,
                'user_id' => $userId,
                'ip_hash' => SiteVisit::hashIp($request->ip()),
                'browser' => $this->browser($userAgent),
                'platform' => $this->platform($userAgent),
                'device_type' => $this->deviceType($userAgent),
                'referrer_host' => $this->referrerHost($request),
                'entry_path' => $path,
                'last_path' => $path,
                'page_views' => 1,
                'started_at' => $now,
                'last_activity_at' => $now,
            ]);

            return;
        }

        /*
         * `user_id` só é preenchido, nunca trocado: se o visitante entrou na
         * conta no meio da visita, o vínculo passa a existir; o contrário
         * (logout) mantém o histórico de quem estava conectado.
         */
        $visit->forceFill([
            'user_id' => $visit->user_id ?? $userId,
            'last_path' => $path,
            'last_activity_at' => $now,
            'page_views' => $visit->page_views + 1,
        ])->save();
    }

    private function close(SiteVisit $visit): void
    {
        $visit->forceFill(['ended_at' => $visit->last_activity_at])->save();
    }

    private function visitorId(Request $request): string
    {
        $cookieName = (string) config('access_log.cookie', 'arcana_visitor');
        $current = $request->cookie($cookieName);

        if (is_string($current) && Str::isUuid($current)) {
            return $current;
        }

        $visitorId = (string) Str::uuid();

        Cookie::queue(
            $cookieName,
            $visitorId,
            max(1, (int) config('access_log.cookie_lifetime_days', 730)) * 24 * 60,
            '/',
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            'lax',
        );

        return $visitorId;
    }

    private function path(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return Str::limit($path, 255, '');
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if ($referrer === null || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $host === $request->getHost()) {
            return null;
        }

        return Str::limit($host, 120, '');
    }

    private function browser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'EdgA/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    private function platform(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Windows NT') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad'), str_contains($userAgent, 'iPod') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }

    private function deviceType(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'iPad') => 'tablet',
            str_contains($userAgent, 'Tablet') => 'tablet',
            str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile') => 'tablet',
            str_contains($userAgent, 'Mobi'), str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPod') => 'mobile',
            $userAgent === '' => null,
            default => 'desktop',
        };
    }
}
