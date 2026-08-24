<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio de un producto en una lista.
 *
 * Puede ser especifico de una variante y de una presentacion, y activarse
 * a partir de cierta cantidad (min_quantity), que es como se arman los
 * precios por volumen.
 */
class ProductPrice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'min_quantity' => 'float',
            'margin_percent' => 'float',
            'is_manual' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /** Forma que espera Pricing::resolve(). */
    public function toCandidate(): array
    {
        return [
            'price_list_id' => $this->price_list_id,
            'product_unit_id' => $this->product_unit_id,
            'min_quantity' => (float) $this->min_quantity,
            'price' => (float) $this->price,
        ];
    }
}
