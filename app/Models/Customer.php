<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credit_enabled' => 'boolean',
            'credit_limit' => 'float',
            'balance' => 'float',
        ];
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * Lista de precios que aplica: la propia del cliente, y si no tiene,
     * la de su tipo. Null significa que se usa la lista por defecto.
     */
    public function effectivePriceListId(): ?string
    {
        return $this->price_list_id ?? $this->customerType?->price_list_id;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
