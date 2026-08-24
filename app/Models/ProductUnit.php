<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentacion de venta de un producto.
 *
 * El factor dice cuantas unidades base contiene: Unidad = 1, Docena = 12,
 * Caja = 24. Vender una caja descuenta 24 del inventario.
 */
class ProductUnit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['factor' => 'float', 'is_default' => 'boolean', 'is_purchase' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** "Caja (24)" para los selectores. */
    public function label(): string
    {
        $name = $this->unit?->name ?? 'Unidad';

        return $this->factor == 1 ? $name : "{$name} ({$this->factor})";
    }
}
