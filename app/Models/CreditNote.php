<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolucion de una venta.
 *
 * La venta original no se toca: este documento dice que volvio, cuanto se
 * le regreso al cliente y como. Asi el reporte de un mes ya cerrado no
 * cambia porque alguien devolvio algo en el siguiente.
 */
class CreditNote extends Model
{
    use BelongsToTenant, HasUuids;

    /** Se le devuelve el dinero. */
    public const REFUND = 'refund';

    /** Le queda como saldo a favor. */
    public const CREDIT = 'credit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'cost_total' => 'float',
            'restock' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class)->orderBy('position');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isRefund(): bool
    {
        return $this->type === self::REFUND;
    }

    public function typeLabel(): string
    {
        return $this->isRefund() ? 'Dinero devuelto' : 'Saldo a favor';
    }

    public function scopeRegistered($query)
    {
        return $query->where($query->qualifyColumn('status'), 'registered');
    }
}
