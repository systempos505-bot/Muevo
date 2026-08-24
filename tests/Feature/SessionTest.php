<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy;
use Livewire\Livewire;

/**
 * Estas pruebas hacen el viaje completo: iniciar sesion y despues pedir
 * una pagina como lo haria un navegador.
 *
 * Es lo unico que ejercita al proveedor de usuarios, y ahi estaba un
 * fallo que ninguna prueba de componente podia ver: `actingAs` entrega la
 * instancia del usuario directamente, sin pasar por la busqueda en base
 * de datos que hace la sesion en cada peticion.
 */
it('mantiene la sesion despues de registrarse', function () {
    Livewire::test(Register::class)
        ->set('businessName', 'Farmacia La Salud')
        ->set('ownerName', 'Maria Lopez')
        ->set('email', 'maria@lasalud.test')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'clave-segura-1')
        ->set('businessType', 'pharmacy')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    // La empresa se olvida a proposito: en una peticion nueva tiene que
    // volver a deducirse del usuario guardado en la sesion.
    Tenancy::forget();

    $this->get(route('dashboard'))->assertOk()->assertSee('Farmacia La Salud');
});

it('mantiene la sesion despues de iniciarla', function () {
    $context = makeTenant('general', 'ana@negocio.test');
    auth()->logout();
    Tenancy::forget();

    Livewire::test(Login::class)
        ->set('email', 'ana@negocio.test')
        ->set('password', 'clave-segura-1')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    Tenancy::forget();

    $this->get(route('products'))->assertOk();
    expect(auth()->id())->toBe($context['user']->id);
});

it('deduce la empresa a partir del usuario de la sesion', function () {
    $a = makeTenant('general', 'a@negocio.test');
    Product::create([
        'sku' => 'A-1',
        'name' => 'Producto de la empresa A',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
    ]);

    $b = makeTenant('general', 'b@negocio.test');
    Product::create([
        'sku' => 'B-1',
        'name' => 'Producto de la empresa B',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
    ]);

    auth()->logout();
    Tenancy::forget();

    Livewire::test(Login::class)
        ->set('email', 'b@negocio.test')
        ->set('password', 'clave-segura-1')
        ->call('login');

    Tenancy::forget();

    // La peticion tiene que resolver la empresa por su cuenta y no
    // mostrar nada de la otra.
    $this->get(route('products'))
        ->assertOk()
        ->assertSee('Producto de la empresa B')
        ->assertDontSee('Producto de la empresa A');
});

it('rechaza credenciales incorrectas', function () {
    makeTenant('general', 'ana@negocio.test');
    auth()->logout();
    Tenancy::forget();

    Livewire::test(Login::class)
        ->set('email', 'ana@negocio.test')
        ->set('password', 'clave-equivocada')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('no deja entrar a un usuario inactivo', function () {
    $context = makeTenant('general', 'ana@negocio.test');
    $context['user']->update(['status' => 'inactive']);

    auth()->logout();
    Tenancy::forget();

    Livewire::test(Login::class)
        ->set('email', 'ana@negocio.test')
        ->set('password', 'clave-segura-1')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('corta la sesion si la empresa queda suspendida', function () {
    $context = makeTenant('general', 'ana@negocio.test');

    Livewire::test(Login::class)
        ->set('email', 'ana@negocio.test')
        ->set('password', 'clave-segura-1')
        ->call('login');

    Tenancy::withoutScope(fn () => Tenant::whereKey($context['tenant']->id)
        ->update(['status' => 'suspended']));

    Tenancy::forget();

    // Una sesion abierta no puede seguir operando una cuenta suspendida.
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
});

it('permite el mismo correo en dos empresas distintas', function () {
    // El correo es unico dentro de la empresa, no en todo el sistema.
    makeTenant('general', 'compartido@correo.test');

    $second = Tenancy::withoutScope(fn () => Tenant::create([
        'name' => 'Segundo negocio',
        'email' => 'otro@correo.test',
        'business_type' => 'general',
    ]));

    $setup = app(TenantProvisioner::class)->provision($second);

    Tenancy::forTenant($second->id, fn () => User::create([
        'branch_id' => $setup['branch']->id,
        'role_id' => $setup['admin_role']->id,
        'name' => 'Otro dueno',
        'email' => 'compartido@correo.test',
        'password' => 'clave-segura-1',
    ]));

    expect(User::allTenants()->where('email', 'compartido@correo.test')->count())->toBe(2);
});
