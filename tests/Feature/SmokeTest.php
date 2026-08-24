<?php

use App\Models\Product;
use App\Models\Unit;
use App\Services\InventoryManager;

/**
 * Carga cada pantalla por su ruta real.
 *
 * Las pruebas de componente no pasan por el layout ni por el middleware,
 * asi que un error en el menu, en una plantilla compartida o en una ruta
 * mal escrita no se veria hasta abrir el navegador.
 */
it('redirige al panel desde la raiz', function () {
    $this->get('/')->assertRedirect('/panel');
});

it('manda al login a quien no ha iniciado sesion', function () {
    $this->get('/panel')->assertRedirect(route('login'));
    $this->get(route('products'))->assertRedirect(route('login'));
    $this->get(route('inventory'))->assertRedirect(route('login'));
});

it('muestra las pantallas publicas', function () {
    $this->get(route('login'))->assertOk()->assertSee('Iniciar sesion');
    $this->get(route('register'))->assertOk()->assertSee('Registra tu negocio');
});

it('carga todas las pantallas con sesion iniciada', function () {
    $context = actingAsTenant('pharmacy');

    $product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto de prueba',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);

    app(InventoryManager::class)->move(
        $product,
        $context['setup']['branch']->id,
        10,
        'initial',
        'Carga inicial',
    );

    $routes = [
        route('dashboard'),
        route('products'),
        route('products.create'),
        route('products.edit', $product),
        route('catalog.categories'),
        route('catalog.brands'),
        route('catalog.units'),
        route('catalog.price-lists'),
        route('inventory'),
        route('inventory.kardex', $product),
        route('pos'),
        route('cash'),
        route('sales'),
        route('purchases'),
        route('purchases.create'),
        route('suppliers'),
    ];

    foreach ($routes as $url) {
        $this->get($url)->assertOk();
    }
});

it('muestra los botones de accion a quien tiene el permiso', function () {
    // Las vistas usan @can, que pasa por el Gate y no por el metodo del
    // modelo. Si esa conexion se rompe, las pantallas cargan bien pero
    // sin un solo boton, y ninguna prueba de componente lo notaria.
    actingAsTenant();

    $this->get(route('products'))->assertSee('+ Nuevo');
    $this->get(route('pos'))->assertSee('Cobrar');
    $this->get(route('cash'))->assertSee('Abrir caja');
    $this->get(route('purchases'))->assertSee('+ Nueva compra');
    $this->get(route('suppliers'))->assertSee('+ Proveedor');
    $this->get(route('catalog.categories'))->assertSee('+ Categoria');
    $this->get(route('catalog.brands'))->assertSee('+ Marca');
    $this->get(route('catalog.units'))->assertSee('+ Unidad');
    $this->get(route('catalog.price-lists'))->assertSee('+ Lista');
});

it('esconde los botones de accion a quien no tiene el permiso', function () {
    $context = actingAsTenant();
    $context['user']->update(['permissions_override' => ['products.edit' => false]]);

    $this->get(route('catalog.categories'))
        ->assertOk()
        ->assertDontSee('+ Categoria');
});

it('cierra la sesion y devuelve al login', function () {
    actingAsTenant();

    $this->post(route('logout'))->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('deja fuera de las pantallas a quien no tiene el permiso', function () {
    $context = actingAsTenant();

    // Un cajero no administra el catalogo ni el inventario.
    $context['user']->update([
        'permissions_override' => [
            '*' => false,
            'products.view' => false,
            'inventory.view' => false,
        ],
    ]);

    $this->get(route('products'))->assertForbidden();
    $this->get(route('inventory'))->assertForbidden();
    $this->get(route('catalog.categories'))->assertForbidden();
});
