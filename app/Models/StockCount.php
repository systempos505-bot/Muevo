<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un inventario fisico: la foto de lo que se conto en una sucursal.
 *
 * No toca la existencia al crearse ni al capturar cantidades. Solo al
 * aplicarse se convierte en movimientos de verdad, y solo por las lineas
 * que de verdad quedaron distintas.
 */
class StockCount extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::OPEN => 'Abierto',
        self::APPLIED => 'Aplicado',
        self::CANCELLED => 'Cancelado',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class, 'count_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Cuantas lineas quedaron distintas de lo que decia el sistema. */
    public function differencesCount(): int
    {
        return $this->items->filter(fn (StockCountItem $item) => $item->difference() != 0.0)->count();
    }

    public function scopeOpen($query)
    {
        return $query->where($query->qualifyColumn('status'), self::OPEN);
    }
}
