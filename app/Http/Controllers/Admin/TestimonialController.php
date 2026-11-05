<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsultationTestimonial;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Moderação dos depoimentos publicados nos perfis dos atendentes.
 *
 * O depoimento é publicado no momento em que o cliente envia. A administração
 * não edita nem apaga: apenas oculta (sai do perfil público) e republica. Cada
 * ação fica registrada em AuditLog com o autor da moderação.
 */
class TestimonialController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ConsultationTestimonial::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['published', 'hidden'])],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'attendant_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $query = $this->filteredQuery($filters);

        $totals = [
            'total' => (clone $query)->count(),
            'published' => (clone $query)->where('is_hidden', false)->count(),
            'hidden' => (clone $query)->where('is_hidden', true)->count(),
            'average' => round((float) (clone $query)->avg('rating'), 1),
        ];

        $testimonials = $query
            ->with(['client', 'attendant.attendantProfile', 'consultation', 'hiddenBy'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.testimonials.index', [
            'testimonials' => $testimonials,
            'filters' => $filters,
            'totals' => $totals,
            'attendants' => $this->attendantOptions(),
        ]);
    }

    public function hide(Request $request, ConsultationTestimonial $testimonial): RedirectResponse
    {
        Gate::authorize('moderate', $testimonial);

        if (! $testimonial->is_hidden) {
            $testimonial->forceFill([
                'is_hidden' => true,
                'hidden_at' => now(),
                'hidden_by_id' => $request->user()?->getKey(),
            ])->save();

            $this->recordModeration($request, $testimonial, 'consultation_testimonial.hidden');
        }

        return back()->with('success', 'Depoimento ocultado. Ele deixa de aparecer no perfil público do atendente.');
    }

    public function publish(Request $request, ConsultationTestimonial $testimonial): RedirectResponse
    {
        Gate::authorize('moderate', $testimonial);

        if ($testimonial->is_hidden) {
            $testimonial->forceFill([
                'is_hidden' => false,
                'hidden_at' => null,
                'hidden_by_id' => null,
            ])->save();

            $this->recordModeration($request, $testimonial, 'consultation_testimonial.published');
        }

        return back()->with('success', 'Depoimento publicado novamente no perfil do atendente.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ConsultationTestimonial>
     */
    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return ConsultationTestimonial::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('message', 'like', $like)
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like))
                        ->orWhereHas('attendant', fn (Builder $attendant) => $attendant->where('name', 'like', $like))
                        ->orWhereHas(
                            'attendant.attendantProfile',
                            fn (Builder $profile) => $profile->where('display_name', 'like', $like),
                        );
                });
            })
            ->when(
                ($filters['status'] ?? null) === 'published',
                fn (Builder $query) => $query->where('is_hidden', false),
            )
            ->when(
                ($filters['status'] ?? null) === 'hidden',
                fn (Builder $query) => $query->where('is_hidden', true),
            )
            ->when(
                isset($filters['rating']),
                fn (Builder $query) => $query->where('rating', (int) $filters['rating']),
            )
            ->when(
                isset($filters['attendant_id']),
                fn (Builder $query) => $query->where('attendant_id', (int) $filters['attendant_id']),
            );
    }

    /**
     * @return Collection<int, User>
     */
    private function attendantOptions(): Collection
    {
        return User::query()
            ->where('role', UserRole::Attendant->value)
            ->with('attendantProfile')
            ->orderBy('name')
            ->get();
    }

    private function recordModeration(Request $request, ConsultationTestimonial $testimonial, string $action): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()?->getKey(),
            'action' => $action,
            'subject_type' => $testimonial->getMorphClass(),
            'subject_id' => $testimonial->getKey(),
            'metadata' => [
                'consultation_id' => $testimonial->consultation_id,
                'client_id' => $testimonial->client_id,
                'attendant_id' => $testimonial->attendant_id,
                'rating' => $testimonial->rating,
            ],
        ]);
    }
}
