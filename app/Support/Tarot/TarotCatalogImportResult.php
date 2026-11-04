<?php

namespace App\Support\Tarot;

/**
 * Contagem do que a importação do catálogo criou, manteve ou ignorou.
 *
 * Serve para o comando operacional relatar o efeito real da execução e para as
 * seederes de demonstração não precisarem reimplementar a contagem.
 */
final readonly class TarotCatalogImportResult
{
    public function __construct(
        public int $decksCreated,
        public int $decksExisting,
        public int $cardsCreated,
        public int $cardsExisting,
        public int $imagesWritten,
        public int $imagesPreserved,
        public int $retiredDecksRemoved,
    ) {}

    public function totalDecks(): int
    {
        return $this->decksCreated + $this->decksExisting;
    }

    public function totalCards(): int
    {
        return $this->cardsCreated + $this->cardsExisting;
    }
}
