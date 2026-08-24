<?php

use App\Livewire\Products\Index;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * El aislamiento entre empresas es lo mas delicado del sistema: si falla,
 * un negocio ve la informacion de otro. En MySQL no hay una barrera a
 * nivel de base de datos, asi que estas pruebas son la red de seguridad.
 */

/** Crea un producto en la empresa que este activa. */
function makeProduct(string $sku, string $name = 'Producto'): Product
{
    return Product::create([
        'sku' => $sku,
        'name' => $name,
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);
}

it('no deja que una empresa vea los productos de otra', function () {
    $a = makeTenant('general', 'a@negocio.test');
    makeProduct('A-1', 'Producto de A');

    $b = makeTenant('general', 'b@negocio.test');
    makeProduct('B-1', 'Producto de B');

    // Estando en la empresa B solo se ve lo de B.
    Tenancy::set($b['tenant']->id);
    expect(Product::pluck('sku')->all())->toBe(['B-1']);

    Tenancy::set($a['tenant']->id);
    expect(Product::pluck('sku')->all())->toBe(['A-1']);
});

it('no encuentra por id un producto de otra empresa', function () {
    makeTenant('general', 'a@negocio.test');
    $productOfA = makeProduct('A-1');

    $b = makeTenant('general', 'b@negocio.test');
    Tenancy::set($b['tenant']->id);

    expect(Product::find($productOfA->id))->toBeNull();

    // Y buscarlo con findOrFail tampoco lo expone: da 404, no el registro.
    Product::findOrFail($productOfA->id);
})->throws(ModelNotFoundException::class);

it('el listado solo muestra los productos de la empresa conectada', function () {
    makeTenant('general', 'a@negocio.test');
    makeProduct('A-1', 'Aspirina de A');

    $b = actingAsTenant('general', 'b@negocio.test');
    makeProduct('B-1', 'Aspirina de B');

    Livewire::test(Index::class)
        ->assertSee('Aspirina de B')
        ->assertDontSee('Aspirina de A');
});

it('la busqueda no filtra productos de otra empresa', function () {
    makeTenant('general', 'a@negocio.test');
    makeProduct('SECRETO-1', 'Producto confidencial de A');

    actingAsTenant('general', 'b@negocio.test');

    // Buscar el SKU exacto de la otra empresa no debe devolver nada.
    Livewire::test(Index::class)
        ->set('search', 'SECRETO-1')
        ->assertDontSee('Producto confidencial de A');

    expect(Product::search('SECRETO-1')->count())->toBe(0);
});

it('cada empresa recibe su propia configuracion inicial', function () {
    $a = makeTenant('pharmacy', 'a@negocio.test');
    $b = makeTenant('hardware', 'b@negocio.test');

    Tenancy::set($a['tenant']->id);
    expect(Category::pluck('name')->all())->toContain('Medicamentos')
        ->and(Category::pluck('name')->all())->not->toContain('Plomeria')
        ->and(PriceList::count())->toBe(3);

    Tenancy::set($b['tenant']->id);
    expect(Category::pluck('name')->all())->toContain('Plomeria')
        ->and(Category::pluck('name')->all())->not->toContain('Medicamentos')
        ->and(PriceList::count())->toBe(3);
});

it('asigna la empresa activa al crear, sin que haya que indicarla', function () {
    $a = makeTenant('general', 'a@negocio.test');

    $product = makeProduct('AUTO-1');

    expect($product->tenant_id)->toBe($a['tenant']->id);
});

it('no devuelve nada si no hay empresa activa', function () {
    makeTenant('general', 'a@negocio.test');
    makeProduct('A-1');

    // Ante un fallo de configuracion es preferible una pantalla vacia
    // a mostrar datos de cualquiera.
    Tenancy::forget();

    expect(Product::count())->toBe(0);
});

it('se niega a crear un registro sin empresa activa', function () {
    Tenancy::forget();

    Product::create(['sku' => 'X', 'name' => 'X', 'cost' => 1]);
})->throws(RuntimeException::class, 'No hay empresa activa');

it('permite consultar todas las empresas solo cuando se pide a proposito', function () {
    makeTenant('general', 'a@negocio.test');
    makeProduct('A-1');

    $b = makeTenant('general', 'b@negocio.test');
    makeProduct('B-1');

    Tenancy::set($b['tenant']->id);

    expect(Product::count())->toBe(1)
        ->and(Product::allTenants()->count())->toBe(2);
});

it('restaura la empresa anterior aunque falle lo que corre adentro', function () {
    $a = makeTenant('general', 'a@negocio.test');
    $b = makeTenant('general', 'b@negocio.test');

    Tenancy::set($a['tenant']->id);

    try {
        Tenancy::forTenant($b['tenant']->id, function () {
            throw new RuntimeException('algo salio mal');
        });
    } catch (RuntimeException) {
        // Se ignora a proposito: lo que se comprueba es el estado despues.
    }

    expect(Tenancy::id())->toBe($a['tenant']->id);
});
