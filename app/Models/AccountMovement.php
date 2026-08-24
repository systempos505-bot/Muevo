<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada o salida de una cuenta.
 *
 * Es solo de escritura: un error se corrige con otro movimiento, nunca
 * alterando el viejo. El saldo guardado permite leer la cuenta sin
 * recalcular toda la historia.
 */
class AccountMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'exchange_rate' => 'float',
            'amount_primary' => 'float',
            'balance' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEntry(): bool
    {
        return $this->direction === 'in';
    }

    /** Con signo, para sumar flujos. */
    public function signedAmount(): float
    {
        return $this->isEntry() ? $this->amount : -$this->amount;
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'sale' => 'Venta',
            'purchase' => 'Compra',
            'expense' => 'Gasto',
            'transfer' => 'Traslado',
            'customer_payment' => 'Abono de cliente',
            'supplier_payment' => 'Pago a proveedor',
            default => 'Manual',
        };
    }
}
