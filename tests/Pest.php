<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Las pruebas de funcionalidad usan la aplicacion completa y una base de
 * datos limpia en cada una. Las de unidad (Pricing) no tocan la base, asi
 * que corren sueltas y rapido.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Crea una empresa completa y devuelve su usuario administrador.
 *
 * Deja la empresa activa, para que las consultas de la prueba se filtren
 * como lo harian en una peticion real.
 *
 * @return array{tenant: Tenant, user: User, setup: array}
 */
function makeTenant(string $businessType = 'general', string $email = 'dueno@negocio.test'): array
{
    $tenant = Tenancy::withoutScope(fn () => Tenant::create([
        'name' => 'Negocio de prueba',
        'email' => $email,
        'business_type' => $businessType,
    ]));

    $setup = app(TenantProvisioner::class)->provision($tenant, 'USD', '$', 15);

    $user = Tenancy::forTenant($tenant->id, fn () => User::create([
        'branch_id' => $setup['branch']->id,
        'role_id' => $setup['admin_role']->id,
        'name' => 'Dueno',
        'email' => $email,
        'password' => 'clave-segura-1',
    ]));

    Tenancy::set($tenant->id);

    return ['tenant' => $tenant, 'user' => $user, 'setup' => $setup];
}

/** Crea una empresa y deja su administrador con la sesion iniciada. */
function actingAsTenant(string $businessType = 'general', string $email = 'dueno@negocio.test'): array
{
    $context = makeTenant($businessType, $email);

    test()->actingAs($context['user']);

    return $context;
}
