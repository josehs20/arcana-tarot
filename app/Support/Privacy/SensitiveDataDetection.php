<?php

namespace App\Support\Privacy;

use App\Enums\SensitiveDataCategory;
use Illuminate\Support\Str;

/**
 * Resultado de uma análise de texto do chat.
 *
 * Guarda as categorias encontradas, os valores mascarados e um trecho seguro
 * para auditoria. Nenhum dado pessoal completo é exposto por esta classe.
 */
final class SensitiveDataDetection
{
    private const ExcerptLimit = 500;

    public readonly string $maskedText;

    public readonly string $maskedExcerpt;

    /**
     * @param  list<SensitiveDataMatch>  $matches
     */
    public function __construct(public readonly string $scan, public readonly array $matches)
    {
        $maskedText = $this->mask($scan, $matches);

        $this->maskedText = $maskedText;
        $this->maskedExcerpt = Str::limit($maskedText, self::ExcerptLimit);
    }

    public static function empty(string $body): self
    {
        return new self(trim($body), []);
    }

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }

    public function primaryCategory(): ?SensitiveDataCategory
    {
        return $this->matches[0]->category ?? null;
    }

    /**
     * @return list<SensitiveDataCategory>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->matches as $match) {
            if (! in_array($match->category, $categories, true)) {
                $categories[] = $match->category;
            }
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(
            fn (SensitiveDataCategory $category): string => $category->label(),
            $this->categories(),
        );
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            fn (SensitiveDataCategory $category): string => $category->value,
            $this->categories(),
        );
    }

    public function summary(): string
    {
        $labels = $this->labels();
        $count = count($labels);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $labels[0];
        }

        return implode(', ', array_slice($labels, 0, -1)).' e '.$labels[$count - 1];
    }

    /**
     * @return list<array{category: string, label: string, masked: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (SensitiveDataMatch $match): array => [
            'category' => $match->category->value,
            'label' => $match->category->label(),
            'masked' => $match->masked(),
        ], $this->matches);
    }

    /**
     * @param  list<SensitiveDataMatch>  $matches
     */
    private function mask(string $source, array $matches): string
    {
        $masked = $source;

        foreach (array_reverse($matches) as $match) {
            $masked = substr_replace($masked, $match->masked(), $match->offset, $match->length());
        }

        $masked = preg_replace('/\s+/u', ' ', $masked) ?? $masked;

        return trim($masked);
    }
}
