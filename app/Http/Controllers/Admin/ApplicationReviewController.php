<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewAttendantApplicationRequest;
use App\Models\AttendantApplication;
use App\Support\AttendantApplicationReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicationReviewController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AttendantApplication::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $applications = AttendantApplication::query()
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->whereDate('submitted_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->whereDate('submitted_at', '<=', $filters['date_to']))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->with('reviewer')
            ->paginate(15)
            ->withQueryString();

        $statusCounts = AttendantApplication::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('admin.applications.index', [
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => ApplicationStatus::cases(),
            'pendingCount' => (int) ($statusCounts[ApplicationStatus::Pending->value] ?? 0),
            'statusCounts' => $statusCounts,
        ]);
    }

    public function show(AttendantApplication $application): View
    {
        Gate::authorize('view', $application);

        $application->load(['reviewer', 'auditLogs.actor']);

        return view('admin.applications.show', [
            'application' => $application,
        ]);
    }

    public function start(
        ReviewAttendantApplicationRequest $request,
        AttendantApplication $application,
        AttendantApplicationReview $review,
    ): RedirectResponse {
        Gate::authorize('review', $application);

        $review->start($application, $request->user(), $request->validated('internal_note'));

        return $this->reviewed($application, 'Análise assumida. A candidatura está em revisão.');
    }

    public function approve(
        ReviewAttendantApplicationRequest $request,
        AttendantApplication $application,
        AttendantApplicationReview $review,
    ): RedirectResponse {
        Gate::authorize('review', $application);

        $review->approve($application, $request->user(), $request->validated('internal_note'));

        return $this->reviewed($application, 'Candidatura aprovada. Nenhum acesso foi criado automaticamente.');
    }

    public function reject(
        ReviewAttendantApplicationRequest $request,
        AttendantApplication $application,
        AttendantApplicationReview $review,
    ): RedirectResponse {
        Gate::authorize('review', $application);

        $review->reject($application, $request->user(), $request->validated('internal_note'));

        return $this->reviewed($application, 'Candidatura rejeitada. A decisão foi registrada.');
    }

    private function reviewed(AttendantApplication $application, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.applications.show', $application)
            ->with('success', $message);
    }
}
