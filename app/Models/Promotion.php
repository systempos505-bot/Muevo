<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Pricing;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promocion de venta.
 *
 * No toca el precio del producto: calcula un descuento sobre la linea.
 * Asi el ticket puede mostrar el precio de lista y el ahorro por
 * separado, que es lo que hace que el cliente vea la oferta.
 */
class Promotion extends Model
{
    use BelongsToTenant, HasUuids;

    /** Lleva N y paga M. */
    public const NXM = 'nxm';

    /** Un porcentaje menos. */
    public const PERCENT = 'percent';

    /** Un monto fijo menos por unidad. */
    public const AMOUNT = 'amount';

    /** Precio cerrado por un paquete de N unidades. */
    public const BUNDLE = 'bundle_price';

    protected $guarded = ['id'];

    protected $attributes = [
        'applies_to_all' => false,
        'buy_quantity' => 0,
        'get_quantity' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'bundle_price' => 0,
        'min_quantity' => 1,
        'priority' => 0,
        'combinable' => false,
        'status' => 'active',
        'times_used' => 0,
    ];

    protected function casts(): array
    {
        return [
            'applies_to_all' => 'boolean',
            'combinable' => 'boolean',
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'discount_percent' => 'float',
            'discount_amount' => 'float',
            'bundle_price' => 'float',
            'min_quantity' => 'float',
            'max_uses_per_line' => 'integer',
            'priority' => 'integer',
            'times_used' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekdays' => 'array',
        ];
    }

    // ---------------------------------------------------------
    // Relaciones
    // ---------------------------------------------------------

    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function uses(): HasMany
    {
        return $this->hasMany(SaleItemPromotion::class);
    }

    // ---------------------------------------------------------
    // Consultas
    // ---------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('status'), 'active');
    }

    // ---------------------------------------------------------
    // Vigencia
    // ---------------------------------------------------------

    /**
     * Si la promocion corre en ese instante.
     *
     * Cada acotacion en nulo significa "sin limite por ese lado": una
     * promocion recien creada, sin fechas ni horarios, corre siempre.
     */
    public function runsAt(?Carbon $moment = null): bool
    {
        $moment = $moment ?? now();

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->starts_on && $moment->lt($this->starts_on->startOfDay())) {
            return false;
        }

        if ($this->ends_on && $moment->gt($this->ends_on->endOfDay())) {
            return false;
        }

        if (is_array($this->weekdays) && $this->weekdays !== []
            && ! in_array($moment->isoWeekday(), array_map('intval', $this->weekdays), true)) {
            return false;
        }

        return $this->runsAtThisHour($moment);
    }

    /**
     * Franja horaria. Se admite una que cruce la medianoche (de 22:00 a
     * 02:00), que en un turno de noche es lo normal.
     */
    protected function runsAtThisHour(Carbon $moment): bool
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return true;
        }

        $now = $moment->format('H:i:s');
        $from = substr((string) $this->starts_at, 0, 8);
        $to = substr((string) $this->ends_at, 0, 8);

        return $from <= $to
            ? $now >= $from && $now <= $to
            : $now >= $from || $now <= $to;
    }

    /** Si ya paso su fecha de fin. */
    public function hasExpired(): bool
    {
        return $this->ends_on !== null && now()->gt($this->ends_on->endOfDay());
    }

    // ---------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------

    /** Como se describe la promocion en una etiqueta corta. */
    public function badge(): string
    {
        return match ($this->type) {
            self::NXM => "{$this->buy_quantity}x".max(0, $this->buy_quantity - $this->get_quantity),
            self::PERCENT => Pricing::round($this->discount_percent, 2).'% menos',
            self::AMOUNT => Pricing::round($this->discount_amount, 2).' menos',
            self::BUNDLE => "{$this->buy_quantity} por ".Pricing::round($this->bundle_price, 2),
            default => $this->name,
        };
    }

    /** Cuantas unidades hacen falta para que la promocion arranque. */
    public function triggerQuantity(): float
    {
        return match ($this->type) {
            self::NXM, self::BUNDLE => max((float) $this->buy_quantity, 1.0),
            default => max((float) $this->min_quantity, 0.0),
        };
    }
}
