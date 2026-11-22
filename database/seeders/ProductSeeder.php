<?php

namespace Database\Seeders;

use App\Models\Product;

/**
 * Vitrine inicial da home em ambiente de demonstração.
 *
 * É idempotente: reexecutar atualiza preço/créditos pelo título e preserva as
 * compras já vinculadas (que guardam a própria cópia dos valores).
 */
class ProductSeeder extends DemoSeeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const PRODUCTS = [
        [
            'title' => 'Consulta rápida',
            'description' => 'Um pacote enxuto para uma pergunta objetiva, com os créditos liberados na carteira após a confirmação do pagamento.',
            'credits_amount' => 10,
            'price_cents' => 2_900,
            'sort_order' => 10,
        ],
        [
            'title' => 'Leitura completa',
            'description' => 'Créditos suficientes para uma leitura mais longa, com tempo para explorar as cartas e os detalhes da sua pergunta.',
            'credits_amount' => 30,
            'price_cents' => 7_900,
            'sort_order' => 20,
        ],
        [
            'title' => 'Imersão no tarô',
            'description' => 'O maior pacote da vitrine: mais tempo de conversa e liberdade para voltar às cartas em outro momento.',
            'credits_amount' => 60,
            'price_cents' => 14_900,
            'sort_order' => 30,
        ],
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        foreach (self::PRODUCTS as $product) {
            Product::query()->updateOrCreate(
                ['title' => $product['title']],
                $product + ['is_active' => true],
            );
        }
    }
}
