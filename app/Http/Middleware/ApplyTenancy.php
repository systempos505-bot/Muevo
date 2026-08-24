<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa activa a partir del usuario autenticado.
 *
 * Corre en cada peticion, antes que cualquier consulta. Si el usuario no
 * ha iniciado sesion, la empresa queda sin fijar y el scope global de los
 * modelos no devuelve nada, que es el comportamiento seguro.
 *
 * Tambien cierra la sesion si la empresa quedo suspendida o si el usuario
 * fue apagado, para que ni una cuenta cancelada ni alguien a quien se le
 * quito el acceso sigan operando con una sesion vieja.
 */
class ApplyTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            Tenancy::set($user->tenant_id);

            if ($user->tenant?->status === 'suspended') {
                return $this->kickOut('La cuenta esta suspendida. Contacta a soporte.');
            }

            // Apagar a alguien tiene que sacarlo de verdad. Sin esto,
            // quien ya tenia la sesion abierta seguiria vendiendo hasta
            // que se le ocurriera cerrarla.
            if ($user->status !== 'active') {
                return $this->kickOut('Tu acceso fue desactivado. Habla con el administrador.');
            }
        }

        return $next($request);
    }

    /** Cierra la sesion y manda al login con el motivo. */
    protected function kickOut(string $message): Response
    {
        auth()->logout();
        Tenancy::forget();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /** Limpia el estado al terminar, para que no se filtre entre peticiones. */
    public function terminate(Request $request, Response $response): void
    {
        Tenancy::forget();
    }
}
