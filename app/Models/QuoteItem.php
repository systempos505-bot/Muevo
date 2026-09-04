<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    /**
     * La linea no lleva fechas propias: nace y muere con su cotizacion,
     * y la fecha que importa es la de la cotizacion completa.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_factor' => 'float',
            'base_quantity' => 'float',
            'unit_price' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'net' => 'float',
            'total' => 'float',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
