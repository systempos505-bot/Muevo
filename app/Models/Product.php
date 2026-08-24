<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Pricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    /**
     * Los mismos valores por defecto que tiene la tabla.
     *
     * Sin esto, un producto recien creado trae estos campos nulos hasta
     * que se vuelve a leer de la base, y codigo que consulte track_stock
     * enseguida se comportaria como si fuera un servicio.
     */
    protected $attributes = [
        'type' => 'simple',
        'cost' => 0,
        'track_stock' => true,
        'min_stock' => 0,
        'track_lots' => false,
        'track_expiry' => false,
        'track_serials' => false,
        'expiry_alert_days' => 30,
        'expiry_block_days' => 0,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'float',
            'min_stock' => 'float',
            'max_stock' => 'float',
            'track_stock' => 'boolean',
            'track_lots' => 'boolean',
            'track_expiry' => 'boolean',
            'track_serials' => 'boolean',
            'expiry_alert_days' => 'integer',
            'expiry_block_days' => 'integer',
        ];
    }

    // ---------------------------------------------------------
    // Relaciones
    // ---------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Presentaciones de venta: unidad, docena, caja... */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderByDesc('is_default')->orderBy('factor');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ProductLot::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    // ---------------------------------------------------------
    // Consultas
    // ---------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'active');
    }

    /**
     * Busca por nombre, SKU, codigo interno o codigo de barra.
     * Es lo que alimenta tanto el buscador del catalogo como el del POS.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('internal_code', 'like', "%{$term}%")
                ->orWhereHas('barcodes', fn (Builder $b) => $b->where('code', $term));
        });
    }

    // ---------------------------------------------------------
    // Calculos
    // ---------------------------------------------------------

    public function taxRate(): float
    {
        return (float) ($this->tax?->rate ?? 0);
    }

    /** La presentacion que sale seleccionada por defecto en la caja. */
    public function defaultUnit(): ?ProductUnit
    {
        return $this->units->firstWhere('is_default', true) ?? $this->units->first();
    }

    /**
     * Existencia total sumando todas las sucursales, o la de una sola.
     */
    public function stock(?string $branchId = null): float
    {
        return (float) $this->inventories
            ->when($branchId, fn ($c) => $c->where('branch_id', $branchId))
            ->sum('quantity');
    }

    /**
     * Margen que deja un precio de venta, en porcentaje.
     * Null cuando no hay costo con que compararlo.
     */
    public function marginFor(float $price, string $taxMode = Pricing::TAX_INCLUDED): ?float
    {
        $net = Pricing::splitTax($price, $this->taxRate(), $taxMode)['net'];

        return Pricing::margin($this->cost, $net);
    }

    /**
     * Si el producto esta por debajo de su minimo en alguna sucursal.
     * Un minimo en cero significa que no se controla.
     */
    public function isLowStock(?string $branchId = null): bool
    {
        if (! $this->track_stock || $this->min_stock <= 0) {
            return false;
        }

        return $this->stock($branchId) <= $this->min_stock;
    }
}
