<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caja.
 *
 * Nadie puede vender sin uno abierto: es lo que permite cuadrar el
 * efectivo al final del dia y saber quien vendio que.
 */
class Shift extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'float',
            'counted_amount' => 'float',
            'expected_amount' => 'float',
            'difference' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Turno abierto de una caja, si lo hay.
     * Una caja no puede tener dos turnos abiertos a la vez.
     */
    public static function openFor(string $terminalId): ?self
    {
        return static::where('terminal_id', $terminalId)->where('status', 'open')->first();
    }

    // ---------------------------------------------------------
    // Arqueo
    // ---------------------------------------------------------

    /** Ventas cobradas en efectivo durante el turno. */
    public function cashSales(): float
    {
        return (float) SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->where('sales.shift_id', $this->id)
            ->where('sales.status', 'completed')
            ->where('payment_methods.affects_drawer', true)
            ->sum('sale_payments.amount_primary');
    }

    /** El cambio entregado sale del cajon, asi que resta del arqueo. */
    public function changeGiven(): float
    {
        return (float) $this->sales()->where('status', 'completed')->sum('change');
    }

    /**
     * Abonos de clientes recibidos en el turno con una forma de pago que
     * entra al cajon. Es dinero que esta fisicamente ahi, asi que cuenta
     * en el arqueo igual que una venta de contado.
     */
    public function customerPayments(): float
    {
        return (float) CustomerPayment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'customer_payments.payment_method_id')
            ->where('customer_payments.shift_id', $this->id)
            ->where('payment_methods.affects_drawer', true)
            ->sum('customer_payments.amount');
    }

    public function cashIn(): float
    {
        return (float) $this->cashMovements()->where('type', 'in')->sum('amount');
    }

    public function cashOut(): float
    {
        return (float) $this->cashMovements()->where('type', 'out')->sum('amount');
    }

    /**
     * Lo que deberia haber en el cajon: fondo + ventas en efectivo
     * + abonos de clientes - cambio entregado + entradas - salidas.
     */
    public function expectedCash(): float
    {
        return round(
            $this->opening_amount
            + $this->cashSales()
            + $this->customerPayments()
            - $this->changeGiven()
            + $this->cashIn()
            - $this->cashOut(),
            2,
        );
    }

    /** Total vendido en el turno, sin importar la forma de pago. */
    public function salesTotal(): float
    {
        return (float) $this->sales()->where('status', 'completed')->sum('total');
    }

    public function salesCount(): int
    {
        return $this->sales()->where('status', 'completed')->count();
    }
}
