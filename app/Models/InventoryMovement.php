<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una linea del kardex.
 *
 * Es solo de escritura: nunca se edita ni se borra, porque es el respaldo
 * de como se llego a la existencia actual. Corregir un error se hace con
 * un movimiento nuevo, no alterando el anterior.
 */
class InventoryMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'balance' => 'float',
            'unit_cost' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEntry(): bool
    {
        return $this->quantity > 0;
    }

    /** Etiqueta en espanol para mostrar en el kardex. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'initial' => 'Inventario inicial',
            'purchase' => 'Compra',
            'sale' => 'Venta',
            'sale_return' => 'Devolucion de cliente',
            'purchase_return' => 'Devolucion a proveedor',
            'adjustment' => 'Ajuste',
            'transfer_out' => 'Traspaso salida',
            'transfer_in' => 'Traspaso entrada',
            'count' => 'Inventario fisico',
            'loss' => 'Merma',
            default => $this->type,
        };
    }
}
