<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Cadastro da vitrine no painel administrativo.
 */
class AdminProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_product_with_a_brazilian_price(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'title' => 'Leitura completa',
                'description' => 'Um pacote com mais tempo de conversa.',
                'credits_amount' => 30,
                'price' => '79,90',
                'sort_order' => 20,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $product = Product::query()->sole();

        $this->assertSame('Leitura completa', $product->title);
        $this->assertSame(30, $product->credits_amount);
        $this->assertSame(7_990, $product->price_cents);
        $this->assertTrue($product->is_active);
        $this->assertSame(20, $product->sort_order);
        $this->assertTrue(AuditLog::query()->where('action', 'product.created')->exists());
    }

    public function test_admin_can_edit_and_deactivate_a_product(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'price_cents' => 2_900,
            'credits_amount' => 10,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'title' => 'Novo título',
                'description' => null,
                'credits_amount' => 12,
                'price' => '35,50',
                'sort_order' => 5,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success');

        $product->refresh();

        $this->assertSame('Novo título', $product->title);
        $this->assertSame(12, $product->credits_amount);
        $this->assertSame(3_550, $product->price_cents);

        $this->actingAs($admin)
            ->patch(route('admin.products.status', $product), ['is_active' => '0'])
            ->assertRedirect();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'product.deactivated')->exists());
    }

    public function test_product_with_sales_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();

        Purchase::factory()->forProduct($product)->create();

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->getKey()]);
    }

    public function test_product_without_sales_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->getKey()]);
        $this->assertTrue(AuditLog::query()->where('action', 'product.deleted')->exists());
    }

    #[RequiresPhpExtension('gd')]
    public function test_admin_can_upload_a_product_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'title' => 'Produto com imagem',
                'credits_amount' => 10,
                'price' => '29,90',
                'is_active' => '1',
                'image' => UploadedFile::fake()->image('produto.png', 800, 600),
            ])
            ->assertRedirect(route('admin.products.index'));

        $product = Product::query()->sole();

        $this->assertNotNull($product->image_path);
        $this->assertStringStartsWith('products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_admin_can_open_the_product_and_sales_screens(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['title' => 'Produto listado']);
        Purchase::factory()->forProduct($product)->paid()->create([
            'product_title' => 'Produto listado',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('Produto listado');

        $this->actingAs($admin)
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Produto listado');

        $this->actingAs($admin)
            ->get(route('admin.purchases.index'))
            ->assertOk()
            ->assertSee('Produto listado')
            ->assertSee('Pago');
    }

    public function test_client_cannot_access_the_product_admin(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('admin.products.index'))
            ->assertForbidden();
    }

    public function test_invalid_price_is_rejected_before_persisting(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'title' => 'Produto inválido',
                'credits_amount' => 10,
                'price' => 'abc',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('price');

        $this->assertSame(0, Product::query()->count());
    }
}
