<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cambio de precio. Solo de escritura: es el respaldo de por que un
 * producto vale hoy lo que vale.
 */
class PriceHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'price_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_price' => 'float', 'new_price' => 'float', 'created_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
