<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToTenant, HasUuids, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'pin', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'permissions_override' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Permisos efectivos: los del rol, con las excepciones del usuario
     * encima. Asi se le puede quitar un permiso a una persona concreta
     * sin tener que inventarle un rol nuevo.
     *
     * @return array<string, bool>
     */
    public function permissions(): array
    {
        return array_merge(
            $this->role?->permissions ?? [],
            $this->permissions_override ?? [],
        );
    }

    /**
     * Si el usuario puede hacer algo.
     * El comodin '*' del administrador abre todo, pero una excepcion
     * explicita del usuario sigue mandando sobre el.
     */
    public function can($abilities, $arguments = []): bool
    {
        $action = is_string($abilities) ? $abilities : (string) $abilities;
        $permissions = $this->permissions();

        if (array_key_exists($action, $permissions)) {
            return (bool) $permissions[$action];
        }

        return ($permissions['*'] ?? false) === true;
    }
}
