<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia de un producto en una sucursal, en unidad base.
 *
 * La cantidad puede quedar negativa: es preferible registrar la venta y
 * que el faltante quede visible, a rechazarla en el mostrador.
 */
class Inventory extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'avg_cost' => 'float', 'min_stock' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Minimo propio de la sucursal, o el general del producto. */
    public function effectiveMinStock(): float
    {
        return (float) ($this->min_stock ?? $this->product->min_stock);
    }

    /** Valor del inventario a costo, para el reporte valorizado. */
    public function value(): float
    {
        return round($this->quantity * $this->avg_cost, 2);
    }
}
