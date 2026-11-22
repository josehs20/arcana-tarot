<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromotionRequest;
use App\Http\Requests\Admin\UpdatePromotionRequest;
use App\Models\AuditLog;
use App\Models\Promotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cadastro das campanhas "Compre X créditos e ganhe Y".
 *
 * A promoção não altera preço nem saldo: o bônus só é lançado no livro-razão
 * quando o provedor confirma um pagamento, dentro da transação de confirmação.
 */
class PromotionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Promotion::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $promotions = Promotion::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('title', 'like', '%'.$search.'%');
            })
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderByDesc('is_active')
            ->orderByDesc('bonus_credits')
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        return view('admin.promotions.index', [
            'promotions' => $promotions,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Promotion::class);

        return view('admin.promotions.form', [
            'promotion' => new Promotion([
                'is_active' => true,
                'buy_credits' => 10,
                'bonus_credits' => 2,
            ]),
        ]);
    }

    public function store(StorePromotionRequest $request): RedirectResponse
    {
        $promotion = DB::transaction(function () use ($request): Promotion {
            $promotion = Promotion::query()->create($this->payload($request));

            $this->audit($promotion, 'promotion.created', $request->user()->getKey(), $promotion->only([
                'title',
                'buy_credits',
                'bonus_credits',
                'is_active',
            ]));

            return $promotion;
        });

        return redirect()
            ->route('admin.promotions.index')
            ->with('success', 'Promoção "'.$promotion->title.'" criada. Ela aparece na home e na carteira enquanto estiver ativa.');
    }

    public function edit(Promotion $promotion): View
    {
        Gate::authorize('update', $promotion);

        return view('admin.promotions.form', [
            'promotion' => $promotion,
        ]);
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        DB::transaction(function () use ($request, $promotion): void {
            $promotion->fill($this->payload($request))->save();

            $this->audit($promotion, 'promotion.updated', $request->user()->getKey(), $promotion->only([
                'title',
                'buy_credits',
                'bonus_credits',
                'is_active',
            ]));
        });

        return redirect()
            ->route('admin.promotions.edit', $promotion)
            ->with('success', 'Promoção atualizada. Pagamentos já confirmados mantêm o bônus concedido.');
    }

    public function updateStatus(Request $request, Promotion $promotion): RedirectResponse
    {
        Gate::authorize('changeStatus', $promotion);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        DB::transaction(function () use ($promotion, $active, $request): void {
            $promotion->forceFill(['is_active' => $active])->save();

            $this->audit($promotion, $active ? 'promotion.activated' : 'promotion.deactivated', $request->user()->getKey(), [
                'is_active' => $active,
            ]);
        });

        return back()->with(
            'success',
            $active
                ? 'Promoção ativada. O bônus volta a ser oferecido na home e na carteira.'
                : 'Promoção inativada. Ela deixa de aparecer no site e de conceder bônus em novas compras.',
        );
    }

    public function destroy(Request $request, Promotion $promotion): RedirectResponse
    {
        Gate::authorize('delete', $promotion);

        DB::transaction(function () use ($promotion, $request): void {
            $promotion->forceFill(['is_active' => false])->save();
            $promotion->delete();

            $this->audit($promotion, 'promotion.deleted', $request->user()->getKey(), [
                'title' => $promotion->title,
            ]);
        });

        return redirect()
            ->route('admin.promotions.index')
            ->with('success', 'Promoção removida. O histórico de pagamentos permanece íntegro.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StorePromotionRequest|UpdatePromotionRequest $request): array
    {
        $data = $request->validated();

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'buy_credits' => (int) $data['buy_credits'],
            'bonus_credits' => (int) $data['bonus_credits'],
            'is_active' => (bool) $data['is_active'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Promotion $promotion, string $action, ?int $actorId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $promotion->getMorphClass(),
            'subject_id' => $promotion->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
