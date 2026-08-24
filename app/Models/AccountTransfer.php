<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Traslado de dinero entre dos cuentas. */
class AccountTransfer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_from' => 'float',
            'amount_to' => 'float',
            'exchange_rate' => 'float',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Si cruzo monedas distintas. */
    public function isCrossCurrency(): bool
    {
        return $this->exchange_rate != 1.0 || $this->amount_from != $this->amount_to;
    }
}
