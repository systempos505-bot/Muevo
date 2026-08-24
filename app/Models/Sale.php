<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'paid' => 'float',
            'change' => 'float',
            'cost_total' => 'float',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** Lo que ya se le devolvio al cliente de esta venta. */
    public function returnedTotal(): float
    {
        return round((float) $this->creditNotes()->registered()->sum('total'), 2);
    }

    /** Si todavia queda algo por devolver. */
    public function hasReturnableItems(): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        return $this->items->contains(fn (SaleItem $item) => $item->returnableQuantity() > 0);
    }

    /**
     * Utilidad de la venta: lo cobrado sin impuesto, menos el costo de
     * la mercancia. Se usa el costo guardado en la linea, no el actual.
     */
    public function profit(): float
    {
        return round($this->total - $this->tax - $this->cost_total, 2);
    }

    public function scopeCompleted($query)
    {
        return $query->where($query->qualifyColumn('status'), 'completed');
    }
}
