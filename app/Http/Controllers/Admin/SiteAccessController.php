<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteVisit;
use App\Support\DashboardTrend;
use App\Support\SiteVisitMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tela "Acessos": panorama de tráfego e lista de visitas do site.
 *
 * A visão é agregada e pseudônima: mostra navegador, plataforma, páginas e
 * horários, nunca IP, conteúdo de conversa ou dado de pagamento. Visitas
 * anônimas aparecem com um código curto derivado do cookie aleatório.
 */
class SiteAccessController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access-admin-panel');

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['7', '14', '30'])],
            'audience' => ['nullable', Rule::in(['registered', 'anonymous'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $days = (int) ($filters['period'] ?? 14);
        $audience = $filters['audience'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));
        $start = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        $visits = SiteVisit::query()
            ->with('user')
            ->where('started_at', '>=', $start)
            ->when($audience === 'registered', fn ($query) => $query->registered())
            ->when($audience === 'anonymous', fn ($query) => $query->anonymous())
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('visitor_id', 'like', $term)
                        ->orWhere('entry_path', 'like', $term)
                        ->orWhere('last_path', 'like', $term)
                        ->orWhereHas('user', function ($query) use ($term): void {
                            $query->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                });
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.access.index', [
            'visits' => $visits,
            'filters' => [
                'period' => (string) $days,
                'audience' => $audience ?? '',
                'q' => $search,
            ],
            'days' => $days,
            'trend' => DashboardTrend::siteVisits($days, $audience),
            'metrics' => SiteVisitMetrics::summary($start, $audience),
        ]);
    }
}
