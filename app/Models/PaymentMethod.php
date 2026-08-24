<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Forma de pago. `affects_drawer` decide si el monto cuenta en el arqueo
 * de caja; `allows_change` si se puede recibir de mas y dar cambio.
 */
class PaymentMethod extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['affects_drawer' => 'boolean', 'allows_change' => 'boolean'];
    }

    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
