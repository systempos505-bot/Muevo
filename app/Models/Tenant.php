<?php

namespace App\Models;

use App\Services\Pricing;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una empresa del sistema. Todo lo demas cuelga de aqui.
 *
 * No usa BelongsToTenant: es la raiz, no pertenece a nadie.
 */
class Tenant extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
            'price_decimals' => 'integer',
            'qty_decimals' => 'integer',
        ];
    }

    /**
     * Modo de impuesto de la empresa, tal como lo espera el motor de precios.
     */
    public function taxMode(): string
    {
        return $this->prices_include_tax
            ? Pricing::TAX_INCLUDED
            : Pricing::TAX_ADDED;
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function primaryCurrency(): HasOne
    {
        return $this->hasOne(Currency::class)->where('is_primary', true);
    }
}
