<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Conteúdo editável de uma seção do site.
 *
 * Quando não existe registro para uma chave, o site usa o texto padrão
 * definido em `config/site.php` (ver App\Support\SiteContent).
 *
 * @property string $key
 * @property string|null $eyebrow
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $body
 * @property string|null $image_path
 * @property string|null $image_alt
 * @property string|null $primary_cta_label
 * @property string|null $primary_cta_target
 * @property string|null $secondary_cta_label
 * @property string|null $secondary_cta_target
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'key',
    'eyebrow',
    'title',
    'subtitle',
    'body',
    'image_path',
    'image_alt',
    'primary_cta_label',
    'primary_cta_target',
    'secondary_cta_label',
    'secondary_cta_target',
    'is_published',
    'sort_order',
])]
class SiteSection extends Model
{
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
