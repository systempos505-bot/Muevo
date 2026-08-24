<?php

namespace App\Auth;

use App\Support\Tenancy;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proveedor de usuarios que ignora el filtro de empresa.
 *
 * La autenticacion es el unico momento en que hay que consultar usuarios
 * sin saber todavia a que empresa pertenecen: la empresa se deduce del
 * usuario, no al reves. Con el scope activo la consulta no encontraria a
 * nadie y nadie podria iniciar sesion.
 *
 * Es una excepcion acotada y deliberada: solo aplica a la busqueda del
 * usuario que se esta autenticando. Una vez identificado, el middleware
 * fija su empresa y todo lo demas vuelve a filtrarse con normalidad.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScope('tenant');
    }

    /**
     * Al recuperar al usuario de la sesion se aprovecha para dejar fijada
     * su empresa, de modo que cualquier consulta que ocurra antes del
     * middleware ya salga filtrada.
     */
    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        if ($user !== null && ! Tenancy::check()) {
            Tenancy::set($user->tenant_id);
        }

        return $user;
    }
}
