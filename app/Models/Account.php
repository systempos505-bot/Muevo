<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de pago: donde vive el dinero del negocio.
 * La caja chica, el banco, la cuenta digital.
 */
class Account extends Model
{
    use BelongsToTenant, HasUuids;

    /** Etiquetas de los tipos, para no repetirlas en cada vista. */
    public const TYPES = [
        'cash' => 'Efectivo',
        'bank' => 'Banco',
        'card' => 'Tarjeta',
        'digital' => 'Cuenta digital',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'float', 'is_default' => 'boolean'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Saldo convertido a la moneda principal de la empresa. */
    public function balanceInPrimary(): float
    {
        return round($this->balance * (float) ($this->currency?->rate ?? 1), 2);
    }

    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('status'), 'active');
    }
}
