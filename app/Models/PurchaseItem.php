<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de compra.
 *
 * Guarda el costo en la presentacion comprada (una caja) y por unidad
 * base (una pieza). El segundo es el que alimenta el margen de venta:
 * comprar una caja de 24 a 240 significa que la pieza cuesta 10.
 */
class PurchaseItem extends Model
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
            'unit_cost' => 'float',
            'base_unit_cost' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'net' => 'float',
            'total' => 'float',
            'expiry_date' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
