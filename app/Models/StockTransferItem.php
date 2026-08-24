<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linea de un traspaso. Siempre en unidad base. */
class StockTransferItem extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_sent' => 'float',
            'quantity_received' => 'float',
            'unit_cost' => 'float',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que salio y no llego en esta linea. */
    public function shortfall(): float
    {
        if ($this->quantity_received === null) {
            return 0.0;
        }

        return round($this->quantity_sent - $this->quantity_received, 3);
    }
}
