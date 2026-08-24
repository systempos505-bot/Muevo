<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol con sus permisos. Los roles de sistema se copian a cada empresa
 * al registrarse, para que puedan editarlos sin afectar a las demas.
 */
class Role extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
