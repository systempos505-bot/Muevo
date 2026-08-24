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
 * Tambien cierra la sesion si la empresa quedo suspendida, para que una
 * cuenta cancelada no siga operando con una sesion vieja.
 */
class ApplyTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            Tenancy::set($user->tenant_id);

            if ($user->tenant?->status === 'suspended') {
                auth()->logout();
                Tenancy::forget();

                return redirect()
                    ->route('login')
                    ->withErrors(['email' => 'La cuenta esta suspendida. Contacta a soporte.']);
            }
        }

        return $next($request);
    }

    /** Limpia el estado al terminar, para que no se filtre entre peticiones. */
    public function terminate(Request $request, Response $response): void
    {
        Tenancy::forget();
    }
}
