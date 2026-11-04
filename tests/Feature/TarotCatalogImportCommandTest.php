<?php

namespace Tests\Feature;

use App\Models\TarotCard;
use App\Models\TarotDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Garante o caminho de instalação do catálogo fora dos seeders de demonstração.
 *
 * Em produção `db:seed` é bloqueado; o comando `arcana:import-tarot-catalog` é
 * o que coloca baralhos e cartas na loja. Sem este teste, um deploy novo poderia
 * subir com a vitrine vazia.
 */
class TarotCatalogImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
    }

    public function test_command_installs_the_catalog_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('arcana:import-tarot-catalog')->assertExitCode(0);

        $marseille = TarotDeck::query()->where('slug', 'tarot-de-marseille')->sole();
        $lenormand = TarotDeck::query()->where('slug', 'lenormand')->sole();

        $this->assertTrue($marseille->is_active);
        $this->assertTrue($lenormand->is_active);
        $this->assertSame(78, $marseille->cards()->count());
        $this->assertSame(36, $lenormand->cards()->count());
        $this->assertSame(114, TarotCard::query()->count());

        $this->assertSame(114, TarotCard::query()->whereNotNull('image_path')->count());
        $this->assertSame(114, TarotCard::query()->whereNotNull('image_checksum')->count());

        $firstCard = TarotCard::query()
            ->where('deck_id', $marseille->getKey())
            ->orderBy('sort_order')
            ->firstOrFail();

        $this->assertSame('cards/marseille/'.$firstCard->slug.'.jpg', $firstCard->image_path);
        $this->assertSame('image/jpeg', $firstCard->image_mime);
        $this->assertTrue(Storage::disk('tarot_private')->exists($firstCard->image_path));

        foreach (TarotCard::query()->pluck('image_path') as $path) {
            $this->assertTrue(
                Storage::disk('tarot_private')->exists((string) $path),
                'Frente de carta ausente no disco privado: '.$path,
            );
        }
    }

    public function test_command_is_idempotent(): void
    {
        $this->artisan('arcana:import-tarot-catalog')->assertExitCode(0);
        $this->artisan('arcana:import-tarot-catalog')->assertExitCode(0);

        $this->assertSame(2, TarotDeck::query()->count());
        $this->assertSame(114, TarotCard::query()->count());
        $this->assertSame(78, TarotDeck::query()->where('slug', 'tarot-de-marseille')->sole()->cards()->count());
    }

    public function test_command_preserves_an_image_replaced_by_the_administration(): void
    {
        $deck = TarotDeck::factory()->create(['slug' => 'tarot-de-marseille']);

        $card = TarotCard::factory()->create([
            'deck_id' => $deck->getKey(),
            'slug' => 'o-louco',
            'image_path' => 'cards/admin/o-louco-enviado.jpg',
            'image_mime' => 'image/png',
            'image_size' => 12,
            'image_checksum' => 'assinatura-do-arquivo-enviado',
        ]);

        Storage::disk('tarot_private')->put('cards/admin/o-louco-enviado.jpg', 'arquivo enviado');

        $this->artisan('arcana:import-tarot-catalog')->assertExitCode(0);

        $card->refresh();

        $this->assertSame('cards/admin/o-louco-enviado.jpg', $card->image_path);
        $this->assertSame('image/png', $card->image_mime);
        $this->assertSame('assinatura-do-arquivo-enviado', $card->image_checksum);
        $this->assertSame(78, $deck->cards()->count());
    }

    public function test_command_fails_with_a_clear_message_when_the_asset_directory_is_missing(): void
    {
        $this->artisan('arcana:import-tarot-catalog', ['--source' => 'database/seeders/assets/inexistente'])
            ->assertExitCode(1);

        $this->assertSame(0, TarotDeck::query()->count());
        $this->assertSame(0, TarotCard::query()->count());
    }
}
