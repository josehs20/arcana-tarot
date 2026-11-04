<?php

namespace Database\Seeders;

use App\Support\Tarot\TarotCatalogImporter;

/**
 * Semeia o catálogo inicial de baralhos e cartas em ambiente de demonstração.
 *
 * A instalação propriamente dita vive em `App\Support\Tarot\TarotCatalogImporter`,
 * compartilhada com o comando `arcana:import-tarot-catalog`, que é o caminho
 * permitido em produção. Aqui só permanece o bloqueio de ambiente.
 */
class TarotDeckSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        app(TarotCatalogImporter::class)->import();
    }
}
