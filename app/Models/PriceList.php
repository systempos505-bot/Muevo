<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lista de precios: Publico, Mayoreo, Distribuidor...
 *
 * En modo 'margin' el precio se calcula desde el costo y se recalcula
 * solo cuando el costo cambia, salvo que alguien lo capture a mano.
 */
class PriceList extends Model
{
    use BelongsToTenant, HasUuids;

    /** Tope de listas por empresa: mas volveria ilegible la pantalla de precios. */
    public const MAX_PER_TENANT = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'margin_percent' => 'float'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function usesMargin(): bool
    {
        return $this->pricing_mode === 'margin';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
