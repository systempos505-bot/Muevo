<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidad de medida del catalogo del negocio: Unidad, Caja, Docena, Kg.
 * El factor de conversion no vive aqui sino en ProductUnit, porque una
 * caja tiene distinta cantidad segun el producto.
 */
class Unit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allows_decimals' => 'boolean'];
    }

    /** Productos que usan esta unidad como su unidad base. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
