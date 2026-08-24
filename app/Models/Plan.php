<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan de suscripcion. Vive a nivel plataforma, no de empresa.
 */
class Plan extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'price_monthly' => 'float',
            'price_yearly' => 'float',
        ];
    }

    /** Limite de un recurso. Null significa ilimitado. */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function hasFeature(string $key): bool
    {
        return ($this->features[$key] ?? false) === true;
    }
}
