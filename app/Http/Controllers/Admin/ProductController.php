<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Support\ProductImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Vitrine de produtos da home.
 *
 * Produtos com vendas registradas não podem ser removidos: o histórico de
 * compras aponta para eles e guarda a própria cópia de título e valores. Para
 * tirar de circulação, o administrador inativa.
 */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $products = Product::query()
            ->withCount('purchases')
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
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('admin.products.form', [
            'product' => new Product([
                'is_active' => true,
                'sort_order' => 0,
                'credits_amount' => 10,
            ]),
        ]);
    }

    public function store(StoreProductRequest $request, ProductImageStorage $images): RedirectResponse
    {
        $imagePath = $request->file('image') !== null
            ? $images->store($request->file('image'))
            : null;

        try {
            $product = DB::transaction(function () use ($request, $imagePath): Product {
                $product = Product::query()->create($this->payload($request, $imagePath));

                $this->audit($product, 'product.created', $request->user()->getKey(), [
                    ...$this->auditSnapshot($product),
                    'image_uploaded' => $imagePath !== null,
                ]);

                return $product;
            });
        } catch (Throwable $exception) {
            $images->delete($imagePath);

            throw $exception;
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Produto "'.$product->title.'" criado. Ele aparece na home da loja enquanto estiver ativo.');
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('admin.products.form', [
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductImageStorage $images): RedirectResponse
    {
        $previousImage = $product->image_path;
        $newImage = $request->file('image') !== null
            ? $images->store($request->file('image'))
            : null;

        try {
            DB::transaction(function () use ($request, $product, $newImage): void {
                $removeImage = ! $newImage && $request->boolean('remove_image');
                $imagePath = $newImage ?? ($removeImage ? null : $product->image_path);

                $product->fill($this->payload($request, $imagePath))->save();

                $this->audit($product, 'product.updated', $request->user()->getKey(), [
                    ...$this->auditSnapshot($product),
                    'image_replaced' => $newImage !== null,
                    'image_removed' => $removeImage,
                ]);
            });
        } catch (Throwable $exception) {
            $images->delete($newImage);

            throw $exception;
        }

        if ($newImage !== null || $request->boolean('remove_image')) {
            $images->delete($previousImage);
        }

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', 'Produto atualizado. Compras já registradas mantêm os valores do momento da compra.');
    }

    public function updateStatus(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('changeStatus', $product);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        DB::transaction(function () use ($product, $active, $request): void {
            $product->forceFill(['is_active' => $active])->save();

            $this->audit($product, $active ? 'product.activated' : 'product.deactivated', $request->user()->getKey(), [
                'is_active' => $active,
            ]);
        });

        return back()->with(
            'success',
            $active
                ? 'Produto ativado. Ele volta a aparecer na vitrine da home.'
                : 'Produto inativado. Ele deixa de aparecer na vitrine e não pode ser comprado.',
        );
    }

    public function destroy(Request $request, Product $product, ProductImageStorage $images): RedirectResponse
    {
        Gate::authorize('delete', $product);

        if ($product->purchases()->exists()) {
            return redirect()
                ->route('admin.products.index')
                ->with('error', 'Este produto já possui vendas registradas e não pode ser removido. Inative-o para retirá-lo da vitrine.');
        }

        $imagePath = $product->image_path;

        DB::transaction(function () use ($product, $request): void {
            $this->audit($product, 'product.deleted', $request->user()->getKey(), $this->auditSnapshot($product));

            $product->delete();
        });

        $images->delete($imagePath);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Produto removido da vitrine.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoreProductRequest $request, ?string $imagePath): array
    {
        $data = $request->validated();

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'credits_amount' => (int) $data['credits_amount'],
            'price_cents' => (int) $data['price_cents'],
            'image_path' => $imagePath,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Product $product): array
    {
        return [
            'title' => $product->title,
            'credits_amount' => $product->credits_amount,
            'price_cents' => $product->price_cents,
            'is_active' => $product->is_active,
            'sort_order' => $product->sort_order,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Product $product, string $action, ?int $actorId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $product->getMorphClass(),
            'subject_id' => $product->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
