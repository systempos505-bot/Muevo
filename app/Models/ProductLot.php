<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote con su fecha de vencimiento.
 *
 * La venta consume primero el lote que vence antes, para que la mercancia
 * proxima a vencer salga primero.
 */
class ProductLot extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'quantity' => 'float', 'cost' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Dias que faltan para vencer. Negativo si ya vencio. */
    public function daysLeft(): ?int
    {
        return $this->expiry_date?->startOfDay()->diffInDays(now()->startOfDay(), false) * -1;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * Si el lote ya no se puede vender: vencido, bloqueado a mano, o
     * dentro de los dias de bloqueo previos al vencimiento que el
     * producto tenga configurados.
     */
    public function isBlockedForSale(): bool
    {
        if ($this->status !== 'active' || $this->isExpired()) {
            return true;
        }

        $blockDays = (int) $this->product->expiry_block_days;

        if ($blockDays <= 0 || $this->expiry_date === null) {
            return false;
        }

        return $this->expiry_date->lte(now()->addDays($blockDays));
    }

    public function scopeSellable($query)
    {
        return $query->where('status', 'active')->where('quantity', '>', 0);
    }

    /**
     * Lotes dentro de la ventana de aviso que cada producto tiene
     * configurada, que puede ser distinta para cada uno.
     *
     * La expresion de fechas cambia entre motores, asi que se arma segun
     * la conexion en uso: MySQL en produccion, SQLite en las pruebas.
     */
    public function scopeWithinAlertWindow($query)
    {
        $driver = $query->getConnection()->getDriverName();

        $expression = match ($driver) {
            'sqlite' => "product_lots.expiry_date <= date('now', '+' || products.expiry_alert_days || ' day')",
            'pgsql' => "product_lots.expiry_date <= current_date + (products.expiry_alert_days || ' day')::interval",
            default => 'product_lots.expiry_date <= date_add(curdate(), interval products.expiry_alert_days day)',
        };

        return $query->whereRaw($expression);
    }

    /** Orden de salida: primero lo que vence antes. */
    public function scopeFefo($query)
    {
        return $query->orderByRaw('expiry_date is null, expiry_date');
    }
}
