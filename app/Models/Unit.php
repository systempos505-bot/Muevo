<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
