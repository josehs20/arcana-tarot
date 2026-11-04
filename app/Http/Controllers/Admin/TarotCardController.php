<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTarotCardRequest;
use App\Http\Requests\Admin\UpdateTarotCardRequest;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Support\TarotCatalogManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TarotCardController extends Controller
{
    public function index(Request $request, TarotDeck $deck): View
    {
        Gate::authorize('viewAny', TarotCard::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $cards = $deck->cards()
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(100)
            ->withQueryString();

        return view('admin.decks.cards.index', [
            'deck' => $deck,
            'cards' => $cards,
            'filters' => $filters,
            'totalCards' => $deck->cards()->count(),
            'activeCards' => $deck->cards()->where('is_active', true)->count(),
        ]);
    }

    public function create(TarotDeck $deck): View
    {
        Gate::authorize('create', TarotCard::class);

        return view('admin.decks.cards.form', [
            'deck' => $deck,
            'card' => new TarotCard([
                'is_active' => true,
                'sort_order' => $this->nextSortOrder($deck),
            ]),
        ]);
    }

    public function store(
        StoreTarotCardRequest $request,
        TarotDeck $deck,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        /** @var UploadedFile $image */
        $image = $request->file('image');

        $catalog->createCard($deck, $this->cardData($request->validated()), $image, $request->user());

        return redirect()
            ->route('admin.decks.cards.index', $deck)
            ->with('success', 'Carta cadastrada com sucesso.');
    }

    public function edit(TarotDeck $deck, TarotCard $card): View
    {
        Gate::authorize('update', $card);
        $this->ensureCardBelongsToDeck($deck, $card);

        return view('admin.decks.cards.form', [
            'deck' => $deck,
            'card' => $card,
        ]);
    }

    public function update(
        UpdateTarotCardRequest $request,
        TarotDeck $deck,
        TarotCard $card,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        $this->ensureCardBelongsToDeck($deck, $card);

        $image = $request->file('image');

        $catalog->updateCard(
            $deck,
            $card,
            $this->cardData($request->validated()),
            $image instanceof UploadedFile ? $image : null,
            $request->user(),
        );

        return redirect()
            ->route('admin.decks.cards.edit', [$deck, $card])
            ->with('success', 'Carta atualizada com sucesso.');
    }

    public function updateStatus(
        Request $request,
        TarotDeck $deck,
        TarotCard $card,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        Gate::authorize('changeStatus', $card);
        $this->ensureCardBelongsToDeck($deck, $card);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];
        $catalog->setCardActive($deck, $card, $active, $request->user());

        return back()->with(
            'success',
            $active
                ? 'Carta ativada. Ela volta a aparecer no seletor quando o baralho também estiver ativo.'
                : 'Carta inativada. O histórico e a imagem original foram preservados.',
        );
    }

    public function move(
        Request $request,
        TarotDeck $deck,
        TarotCard $card,
        TarotCatalogManager $catalog,
    ): RedirectResponse {
        Gate::authorize('changeStatus', $card);
        $this->ensureCardBelongsToDeck($deck, $card);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $moved = $catalog->moveCard($deck, $card, $validated['direction'], $request->user());

        return back()->with(
            $moved ? 'success' : 'info',
            $moved
                ? 'Ordem das cartas atualizada.'
                : 'A carta já está no limite desta ordenação.',
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name: string, sort_order: int, meaning_upright: ?string, meaning_reversed: ?string, is_active: bool}
     */
    private function cardData(array $validated): array
    {
        return [
            'name' => (string) $validated['name'],
            'sort_order' => (int) $validated['sort_order'],
            'meaning_upright' => $validated['meaning_upright'] ?? null,
            'meaning_reversed' => $validated['meaning_reversed'] ?? null,
            'is_active' => (bool) $validated['is_active'],
        ];
    }

    private function nextSortOrder(TarotDeck $deck): int
    {
        return ((int) $deck->cards()->max('sort_order')) + 1;
    }

    private function ensureCardBelongsToDeck(TarotDeck $deck, TarotCard $card): void
    {
        abort_unless($card->deck_id === $deck->getKey(), 404);
    }
}
