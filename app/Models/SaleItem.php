<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una venta.
 *
 * Guarda copia del nombre, del precio y del costo al momento de vender,
 * para que un ticket viejo se pueda reimprimir igual aunque el producto
 * haya cambiado de precio o ya no exista.
 */
class SaleItem extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'base_quantity' => 'float',
            'unit_factor' => 'float',
            'unit_price' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'net' => 'float',
            'total' => 'float',
            'unit_cost' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Utilidad de la linea: lo que quedo despues del costo. */
    public function profit(): float
    {
        return round($this->net - ($this->unit_cost * $this->base_quantity), 2);
    }
}
