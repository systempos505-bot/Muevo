<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CONVERTED = 'converted';

    /** @var array<string, string> */
    public const STATUSES = [
        self::PENDING => 'Pendiente',
        self::APPROVED => 'Aprobada',
        self::REJECTED => 'Rechazada',
        self::CONVERTED => 'Convertida en venta',
    ];

    protected $guarded = ['id'];

    /**
     * Espeja el valor por defecto de la base.
     *
     * Un modelo recien creado tiene null en status hasta que se relee, y
     * quien lo reciba creeria que la cotizacion no tiene estado.
     */
    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'valid_until' => 'date',
            'answered_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function isConverted(): bool
    {
        return $this->status === self::CONVERTED;
    }

    /**
     * Si ya paso la fecha hasta la que se sostenia el precio.
     *
     * Se calcula, no se guarda: sin una tarea programada que lo voltee
     * cada noche, un estado guardado quedaria mintiendo.
     */
    public function isExpired(): bool
    {
        if ($this->isConverted() || $this->isRejected()) {
            return false;
        }

        return $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast();
    }

    /** Si todavia se le puede cambiar algo. */
    public function isEditable(): bool
    {
        return $this->isPending();
    }

    /**
     * Si se puede convertir en venta.
     *
     * Una cotizacion vencida no se convierte sola: el precio ya no esta
     * comprometido, y dejarla pasar seria vender al precio del mes
     * pasado sin que nadie lo decida.
     */
    public function isConvertible(): bool
    {
        return ($this->isPending() || $this->isApproved()) && ! $this->isExpired();
    }

    public function statusLabel(): string
    {
        if ($this->isExpired()) {
            return 'Vencida';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Como se le dice al cliente, venga de la ficha o del campo suelto. */
    public function customerLabel(): string
    {
        return $this->customer?->name ?: $this->customer_name;
    }

    public function scopePending($query)
    {
        return $query->where($query->qualifyColumn('status'), self::PENDING);
    }
}
