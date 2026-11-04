<?php

namespace App\Support\Tarot;

use App\Models\TarotCard;
use App\Models\TarotDeck;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Instala o catálogo inicial de baralhos e cartas em qualquer ambiente.
 *
 * É a mesma rotina usada pela seeder de demonstração e pelo comando
 * operacional `arcana:import-tarot-catalog`. Por isso ela não conhece
 * `APP_ENV`: em produção o comando é o caminho permitido para instalar o
 * catálogo, enquanto a seeder continua restrita a desenvolvimento e
 * homologação.
 *
 * Regras de segurança do procedimento:
 *
 * - baralhos e cartas são localizados pelo `slug`, então reexecutar não
 *   duplica registros;
 * - a imagem enviada pela administração (caminho diferente do acervo) nunca é
 *   sobrescrita nem tem seus metadados alterados;
 * - o conteúdo só é gravado no disco privado quando o arquivo ainda não
 *   existe, e os metadados só são atualizados quando divergem do arquivo;
 * - os baralhos fictícios de versões antigas são removidos, sem tocar nas
 *   cópias de imagem preservadas pelos snapshots do histórico.
 */
final class TarotCatalogImporter
{
    /** Acervo versionado com as frentes de carta, relativo à raiz do projeto. */
    public const DefaultAssetRoot = 'database/seeders/assets/tarot';

    /**
     * Baralhos fictícios criados por versões anteriores do projeto.
     *
     * @var list<string>
     */
    private const RetiredDecks = ['arcana-essencial', 'oraculo-da-lua'];

    /** A administração substituiu a imagem: o registro e o arquivo são preservados. */
    private const ArtworkPreserved = 'preserved';

    /** O arquivo não existia no disco privado e foi gravado agora. */
    private const ArtworkWritten = 'written';

    /** Arquivo e metadados já estavam sincronizados. */
    private const ArtworkUnchanged = 'unchanged';

    /**
     * @param  string|null  $assetRoot  Diretório do acervo; caminhos relativos são resolvidos a partir da raiz do projeto.
     */
    public function __construct(private readonly ?string $assetRoot = null) {}

    public function import(): TarotCatalogImportResult
    {
        $root = $this->resolveAssetRoot();

        if (! is_dir($root)) {
            throw new RuntimeException(
                'Acervo de frentes de carta não encontrado em '.$root
                .'. Envie o diretório '.self::DefaultAssetRoot.' ou informe --source.',
            );
        }

        $disk = Storage::disk((string) config('tarot.image_disk'));
        $directory = trim((string) config('tarot.image_directory'), '/');

        $retiredDecksRemoved = $this->removeRetiredDecks($disk, $directory);

        $decksCreated = 0;
        $decksExisting = 0;
        $cardsCreated = 0;
        $cardsExisting = 0;
        $imagesWritten = 0;
        $imagesPreserved = 0;

        foreach (TarotCatalog::decks() as $deckData) {
            $deck = TarotDeck::query()->firstOrCreate(
                ['slug' => $deckData['slug']],
                [
                    'name' => $deckData['name'],
                    'description' => $deckData['description'],
                    'is_active' => true,
                ],
            );

            if ($deck->wasRecentlyCreated) {
                $decksCreated++;
            } else {
                $decksExisting++;
            }

            foreach ($deckData['cards'] as $sortOrder => $cardData) {
                $card = TarotCard::query()
                    ->where('deck_id', $deck->getKey())
                    ->where('slug', $cardData['slug'])
                    ->first();

                if ($card === null) {
                    $card = TarotCard::query()->create([
                        'deck_id' => $deck->getKey(),
                        'name' => $cardData['name'],
                        'slug' => $cardData['slug'],
                        'sort_order' => $sortOrder,
                        'meaning_upright' => $cardData['upright'],
                        'meaning_reversed' => $cardData['reversed'],
                        'is_active' => true,
                    ]);

                    $cardsCreated++;
                } else {
                    $cardsExisting++;
                }

                $status = $this->storeArtwork($disk, $card, $deckData['assets'], $directory, $root);

                if ($status === self::ArtworkWritten) {
                    $imagesWritten++;
                }

                if ($status === self::ArtworkPreserved) {
                    $imagesPreserved++;
                }
            }
        }

        return new TarotCatalogImportResult(
            $decksCreated,
            $decksExisting,
            $cardsCreated,
            $cardsExisting,
            $imagesWritten,
            $imagesPreserved,
            $retiredDecksRemoved,
        );
    }

    /**
     * Caminhos relativos (`database/seeders/assets/tarot`) são resolvidos a
     * partir da raiz do projeto; caminhos absolutos são usados como vieram.
     */
    private function resolveAssetRoot(): string
    {
        $root = $this->assetRoot;

        if ($root === null || trim($root) === '') {
            return base_path(self::DefaultAssetRoot);
        }

        return is_dir($root) || str_starts_with($root, '/') ? $root : base_path($root);
    }

    /**
     * Copia a frente do acervo para o disco privado e mantém os metadados
     * sincronizados com o arquivo gravado.
     *
     * @return self::Artwork*
     */
    private function storeArtwork(
        Filesystem $disk,
        TarotCard $card,
        string $assets,
        string $directory,
        string $root,
    ): string {
        $path = $directory.'/'.$assets.'/'.$card->slug.'.jpg';
        $source = $root.'/'.$assets.'/'.$card->slug.'.jpg';

        if (! is_file($source)) {
            throw new RuntimeException('Frente de carta ausente no acervo: '.$source);
        }

        if (filled($card->image_path) && $card->image_path !== $path) {
            /*
             * A imagem foi substituída pela administração. O arquivo enviado é
             * preservado e o registro não é alterado.
             */
            return self::ArtworkPreserved;
        }

        $contents = (string) file_get_contents($source);
        $checksum = hash('sha256', $contents);
        $written = false;

        if (! $disk->exists($path)) {
            $disk->put($path, $contents);
            $written = true;
        }

        if (
            $card->image_path === $path
            && $card->image_checksum === $checksum
            && (int) $card->image_size === strlen($contents)
        ) {
            return $written ? self::ArtworkWritten : self::ArtworkUnchanged;
        }

        $card->forceFill([
            'image_path' => $path,
            'image_mime' => 'image/jpeg',
            'image_size' => strlen($contents),
            'image_checksum' => $checksum,
        ])->saveQuietly();

        return $written ? self::ArtworkWritten : self::ArtworkUnchanged;
    }

    /**
     * Remove os baralhos fictícios de versões anteriores e os PNGs de
     * demonstração correspondentes. As mensagens do histórico mantêm a cópia
     * própria da imagem feita no envio.
     */
    private function removeRetiredDecks(Filesystem $disk, string $directory): int
    {
        $decks = TarotDeck::query()
            ->whereIn('slug', self::RetiredDecks)
            ->with('cards')
            ->get();

        foreach ($decks as $deck) {
            foreach ($deck->cards as $card) {
                if (filled($card->image_path) && str_contains($card->image_path, '/demo-')) {
                    $disk->delete($card->image_path);
                }
            }

            $deck->cards()->delete();
            $deck->delete();
        }

        /*
         * Os PNGs de demonstração ficavam soltos no diretório de cartas, então
         * podem sobreviver ao banco em instalações antigas. Nenhum registro
         * ativo aponta para esses caminhos, e as cópias de histórico usam
         * outro diretório.
         */
        foreach ($disk->files($directory) as $file) {
            if (str_starts_with(basename($file), 'demo-')) {
                $disk->delete($file);
            }
        }

        return $decks->count();
    }
}
