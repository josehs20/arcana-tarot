<?php

namespace App\Http\Controllers;

use App\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Heartbeat da visita atual.
 *
 * O JavaScript envia este POST enquanto a aba está visível para que o tempo no
 * site reflita a permanência real na página, e não apenas o intervalo entre
 * page views. Endpoint silencioso: nunca devolve dados do visitante.
 */
class SiteVisitHeartbeatController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! config('access_log.enabled', true)) {
            return response()->noContent();
        }

        $cookieName = (string) config('access_log.cookie', 'arcana_visitor');
        $visitorId = $request->cookie($cookieName);

        if (! is_string($visitorId) || ! Str::isUuid($visitorId)) {
            return response()->noContent();
        }

        $visit = SiteVisit::query()
            ->where('visitor_id', $visitorId)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        if ($visit === null) {
            return response()->noContent();
        }

        $now = now();
        $inactivityMinutes = max(1, (int) config('access_log.inactivity_minutes', 30));

        /*
         * Batimento atrasado (aba em segundo plano, suspensão do notebook) não
         * reabre uma visita já encerrada por inatividade: o próximo page view
         * cria uma nova visita.
         */
        if ($visit->last_activity_at->lt($now->copy()->subMinutes($inactivityMinutes))) {
            return response()->noContent();
        }

        $visit->forceFill([
            'user_id' => $visit->user_id ?? $request->user()?->getKey(),
            'last_activity_at' => $now,
        ])->save();

        return response()->noContent();
    }
}
