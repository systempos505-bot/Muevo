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
     * Si el usuario tiene un permiso.
     *
     * El comodin '*' del administrador abre todo, pero una excepcion
     * explicita sobre el usuario sigue mandando sobre el.
     */
    public function hasPermission(string $ability): bool
    {
        $permissions = $this->permissions();

        if (array_key_exists($ability, $permissions)) {
            return (bool) $permissions[$ability];
        }

        return ($permissions['*'] ?? false) === true;
    }

    /**
     * Se sobreescribe para que `$user->can('products.edit')` consulte los
     * permisos del rol.
     *
     * La directiva `@can` de Blade no pasa por aqui sino por el Gate, que
     * se conecta a `hasPermission` en AppServiceProvider. Ambos caminos
     * tienen que dar la misma respuesta.
     */
    public function can($abilities, $arguments = []): bool
    {
        if (is_string($abilities)) {
            return $this->hasPermission($abilities);
        }

        return parent::can($abilities, $arguments);
    }
}
