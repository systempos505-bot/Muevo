<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variante de un producto: "Rojo / M". Tiene SKU, codigo de barra,
 * stock y precio propios.
 */
class ProductVariant extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'variant_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'variant_id');
    }

    /** Costo propio si lo tiene; si no, el del producto. */
    public function effectiveCost(): float
    {
        return $this->cost ?? (float) $this->product->cost;
    }

    public function stock(?string $branchId = null): float
    {
        return (float) $this->inventories
            ->when($branchId, fn ($c) => $c->where('branch_id', $branchId))
            ->sum('quantity');
    }
}
