<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTarotDeckRequest;
use App\Http\Requests\Admin\UpdateTarotDeckRequest;
use App\Models\TarotDeck;
use App\Support\TarotCatalogManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TarotDeckController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', TarotDeck::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $decks = TarotDeck::query()
            ->withCount([
                'cards',
                'cards as active_cards_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term);
                });
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
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.decks.index', [
            'decks' => $decks,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', TarotDeck::class);

        return view('admin.decks.form', [
            'deck' => new TarotDeck(['is_active' => true]),
        ]);
    }

    public function store(StoreTarotDeckRequest $request, TarotCatalogManager $catalog): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = (bool) $data['is_active'];

        $deck = $catalog->createDeck($data, $request->user());

        return redirect()
            ->route('admin.decks.cards.index', $deck)
            ->with('success', 'Baralho criado. Agora você pode cadastrar as cartas.');
    }

    public function edit(TarotDeck $deck): View
    {
        Gate::authorize('update', $deck);

        $deck->loadCount('cards');

        return view('admin.decks.form', [
            'deck' => $deck,
        ]);
    }

    public function update(
        UpdateTarotDeckRequest $request,
        TarotDeck $deck,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        $data = $request->validated();
        $data['is_active'] = (bool) $data['is_active'];

        $catalog->updateDeck($deck, $data, $request->user());

        return redirect()
            ->route('admin.decks.edit', $deck)
            ->with('success', 'Baralho atualizado com sucesso.');
    }

    public function updateStatus(
        Request $request,
        TarotDeck $deck,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        Gate::authorize('changeStatus', $deck);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];
        $catalog->setDeckActive($deck, $active, $request->user());

        return back()->with(
            'success',
            $active
                ? 'Baralho ativado. As cartas ativas voltam a ficar disponíveis para seleção.'
                : 'Baralho inativado. Nenhum registro ou imagem foi apagado.',
        );
    }
}
