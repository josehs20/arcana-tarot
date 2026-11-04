<?php

namespace App\Models;

use App\Enums\SiteLinkType;
use App\Enums\SiteNavigationGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Link de navegação editável (navbar ou rodapé).
 *
 * @property string $label
 * @property string $group
 * @property string $target_type
 * @property string $target
 * @property bool $opens_in_new_tab
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable([
    'label',
    'group',
    'target_type',
    'target',
    'opens_in_new_tab',
    'is_active',
    'sort_order',
])]
class SiteNavigationLink extends Model
{
    protected function casts(): array
    {
        return [
            'group' => SiteNavigationGroup::class,
            'target_type' => SiteLinkType::class,
            'opens_in_new_tab' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
