<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gasto del negocio: renta, luz, mensajeria, mantenimiento.
 * Sale de una cuenta y baja su saldo.
 */
class Expense extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'subtotal' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'exchange_rate' => 'float',
            'total_primary' => 'float',
            'is_recurring' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function scopeRegistered($query)
    {
        return $query->where($query->qualifyColumn('status'), 'registered');
    }
}
