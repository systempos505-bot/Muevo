<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Que promocion se aplico a una linea de venta y cuanto ahorro.
 *
 * Guarda copia del nombre: renombrar o borrar la promocion despues no
 * debe cambiar como se lee un ticket ya emitido.
 */
class SaleItemPromotion extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount' => 'float', 'free_quantity' => 'float'];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
