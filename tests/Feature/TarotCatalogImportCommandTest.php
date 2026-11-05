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
        $riderWaite = TarotDeck::query()->where('slug', 'rider-waite-smith')->sole();
        $playing = TarotDeck::query()->where('slug', 'baralho-de-52-cartas')->sole();
        $spanish = TarotDeck::query()->where('slug', 'baralho-espanhol')->sole();

        foreach ([$marseille, $lenormand, $riderWaite, $playing, $spanish] as $deck) {
            $this->assertTrue($deck->is_active, 'Baralho inativo após a importação: '.$deck->slug);
        }

        $this->assertSame(78, $marseille->cards()->count());
        $this->assertSame(36, $lenormand->cards()->count());
        $this->assertSame(78, $riderWaite->cards()->count());
        $this->assertSame(52, $playing->cards()->count());
        $this->assertSame(40, $spanish->cards()->count());
        $this->assertSame(284, TarotCard::query()->count());

        $this->assertSame(284, TarotCard::query()->whereNotNull('image_path')->count());
        $this->assertSame(284, TarotCard::query()->whereNotNull('image_checksum')->count());

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

        $this->assertSame(5, TarotDeck::query()->count());
        $this->assertSame(284, TarotCard::query()->count());
        $this->assertSame(78, TarotDeck::query()->where('slug', 'tarot-de-marseille')->sole()->cards()->count());
        $this->assertSame(78, TarotDeck::query()->where('slug', 'rider-waite-smith')->sole()->cards()->count());
    }

    public function test_new_decks_carry_readings_composed_from_the_suit(): void
    {
        $this->artisan('arcana:import-tarot-catalog')->assertExitCode(0);

        $spanishAce = TarotCard::query()
            ->whereHas('deck', fn ($query) => $query->where('slug', 'baralho-espanhol'))
            ->where('slug', 'as-de-ouros')
            ->sole();

        $this->assertStringContainsString('campo material', (string) $spanishAce->meaning_upright);
        $this->assertStringContainsString('campo material', (string) $spanishAce->meaning_reversed);
        $this->assertSame('cards/baralho-espanhol/as-de-ouros.jpg', $spanishAce->image_path);

        $playingQueen = TarotCard::query()
            ->whereHas('deck', fn ($query) => $query->where('slug', 'baralho-de-52-cartas'))
            ->where('slug', 'dama-de-copas')
            ->sole();

        $this->assertStringContainsString('campo afetivo', (string) $playingQueen->meaning_upright);

        $magician = TarotCard::query()
            ->whereHas('deck', fn ($query) => $query->where('slug', 'rider-waite-smith'))
            ->where('slug', 'o-mago')
            ->sole();

        $this->assertTrue($magician->is_active);
        $this->assertSame('cards/rider-waite/o-mago.jpg', $magician->image_path);
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
