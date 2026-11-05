<?php

namespace App\Support\Privacy;

use App\Enums\SensitiveDataCategory;

/**
 * Um trecho identificado no texto do chat.
 *
 * `offset` é a posição em bytes no texto normalizado (o mesmo usado para
 * montar o trecho mascarado). O valor bruto nunca é exibido no painel.
 */
final class SensitiveDataMatch
{
    public function __construct(
        public readonly SensitiveDataCategory $category,
        public readonly string $raw,
        public readonly int $offset,
    ) {}

    public function masked(): string
    {
        return SensitiveDataMasker::mask($this->category, $this->raw);
    }

    public function length(): int
    {
        return strlen($this->raw);
    }
}
