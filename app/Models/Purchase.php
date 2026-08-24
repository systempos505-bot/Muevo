<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compra a un proveedor: la via por la que la mercancia entra al
 * inventario con documento y costo conocido.
 */
class Purchase extends Model
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
            'updates_cost' => 'boolean',
            'due_date' => 'date',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('position');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** Lo que todavia se le debe al proveedor por esta compra. */
    public function balance(): float
    {
        return round($this->total - $this->paid, 2);
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }

    /** Vencida: a credito, con saldo y con fecha de pago ya pasada. */
    public function isOverdue(): bool
    {
        return $this->payment_type === 'credit'
            && ! $this->isPaid()
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }
}
