<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // La autenticacion tiene que poder buscar usuarios sin conocer aun
        // la empresa, porque la empresa se deduce del usuario que entra.
        Auth::provider('tenant_aware', fn ($app, array $config) => new TenantAwareUserProvider(
            $app['hash'],
            $config['model'],
        ));

        // Conecta los permisos del rol con el Gate, que es por donde pasa
        // la directiva @can de Blade. Sin esto las vistas esconderian todos
        // los botones aunque el usuario si tuviera el permiso.
        //
        // Devuelve null (no false) cuando no lo tiene, para no cortar otras
        // reglas de autorizacion que se definan mas adelante.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ?: null);
    }
}
