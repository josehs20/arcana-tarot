<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Consulta somente leitura da trilha de auditoria.
 *
 * A tela é exclusiva de administradores ativos e não possui acesso a
 * mensagens de consulta. Metadados são renderizados escapados.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $rules = [
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];

        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        $filters = $request->validate($rules);
        $search = trim((string) ($filters['q'] ?? ''));

        $logs = AuditLog::query()
            ->with('actor')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('action', 'like', $term)
                        ->orWhere('subject_type', 'like', $term)
                        ->orWhereHas('actor', function ($query) use ($term): void {
                            $query->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                });
            })
            ->when(
                filled($filters['action'] ?? null),
                fn ($query) => $query->where('action', $filters['action']),
            )
            ->when(
                filled($filters['date_from'] ?? null),
                fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            )
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
