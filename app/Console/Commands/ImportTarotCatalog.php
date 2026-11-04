<?php

namespace App\Console\Commands;

use App\Support\Tarot\TarotCatalogImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Instala o catálogo inicial de baralhos e cartas sem depender dos seeders de
 * demonstração.
 *
 * Em produção `db:seed` é bloqueado, mas a loja não pode ficar sem baralhos:
 * este comando usa a mesma rotina idempotente da seeder, cria baralhos e cartas
 * pelo slug e copia as frentes de carta para o disco privado. Reexecutar serve
 * para reparar arquivos ausentes e nunca sobrescreve imagem enviada pela
 * administração.
 */
class ImportTarotCatalog extends Command
{
    protected $signature = 'arcana:import-tarot-catalog
        {--source= : Diretório do acervo de frentes de carta (padrão: database/seeders/assets/tarot)}';

    protected $description = 'Instala o catálogo de baralhos e cartas em qualquer ambiente, inclusive produção';

    public function handle(): int
    {
        $source = $this->option('source');

        $importer = new TarotCatalogImporter(is_string($source) && trim($source) !== '' ? $source : null);

        try {
            $result = $importer->import();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Catálogo instalado: %d baralho(s) (%d criado(s)) e %d carta(s) (%d criada(s)).',
            $result->totalDecks(),
            $result->decksCreated,
            $result->totalCards(),
            $result->cardsCreated,
        ));

        $this->line(sprintf(
            'Imagens no disco privado: %d gravada(s) agora, %d preservada(s) por edição administrativa.',
            $result->imagesWritten,
            $result->imagesPreserved,
        ));

        if ($result->retiredDecksRemoved > 0) {
            $this->line(sprintf('Baralhos fictícios de versões anteriores removidos: %d.', $result->retiredDecksRemoved));
        }

        return self::SUCCESS;
    }
}
