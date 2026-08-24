<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traspaso de mercancia de una sucursal a otra.
 *
 * Tiene dos momentos, salida y llegada, porque en el medio hay mercancia
 * que no esta en ninguna de las dos tiendas.
 */
class StockTransfer extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::DRAFT => 'Borrador',
        self::SENT => 'En camino',
        self::RECEIVED => 'Recibido',
        self::CANCELLED => 'Cancelado',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::DRAFT,
        'total_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'total_cost' => 'float',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id')->orderBy('position');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::SENT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Lo que salio y no llego.
     *
     * Es la cifra que hay que mirar cuando un traspaso se recibe: si no
     * es cero, en el camino se perdio mercancia.
     */
    public function shortfall(): float
    {
        if (! $this->isReceived()) {
            return 0.0;
        }

        return round($this->items->sum(
            fn (StockTransferItem $item) => $item->quantity_sent - (float) $item->quantity_received,
        ), 3);
    }

    /**
     * El faltante escrito como se lee, no como sale del numero.
     *
     * "Faltaron 1 unidades" delata que nadie miro la pantalla.
     */
    public function shortfallLabel(): string
    {
        $missing = $this->shortfall();
        $amount = rtrim(rtrim(number_format($missing, 3), '0'), '.');

        return $missing == 1.0
            ? 'Falto 1 unidad'
            : "Faltaron {$amount} unidades";
    }

    public function scopePending($query)
    {
        return $query->whereIn($query->qualifyColumn('status'), [self::DRAFT, self::SENT]);
    }
}
