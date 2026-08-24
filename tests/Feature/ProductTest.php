<?php

use App\Livewire\Products\Form;
use App\Models\CostHistory;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PriceHistory;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Unit;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant('pharmacy');
    $this->unit = Unit::where('code', 'UND')->first();
});

it('crea un producto simple con su precio', function () {
    $publico = PriceList::where('name', 'Publico')->first();

    Livewire::test(Form::class)
        ->set('name', 'Acetaminofen 500mg')
        ->set('sku', 'MED-001')
        ->set('cost', 10)
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('priceRows', [[
            'price_list_id' => $publico->id,
            'name' => 'Publico',
            'price' => 14.95,
            'margin' => null,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::firstWhere('sku', 'MED-001');

    expect($product)->not->toBeNull()
        ->and($product->name)->toBe('Acetaminofen 500mg')
        ->and($product->cost)->toBe(10.0)
        // La farmacia trae presentacion base con factor 1 sin capturar nada.
        ->and($product->units)->toHaveCount(1)
        ->and($product->units->first()->factor)->toBe(1.0)
        ->and($product->units->first()->is_default)->toBeTrue();

    $price = ProductPrice::where('product_id', $product->id)->first();
    expect($price->price)->toBe(14.95);
});

it('aplica los valores por defecto del giro al crear', function () {
    // Una farmacia arranca con lotes y vencimiento activados; el usuario
    // no tiene que acordarse de prenderlos producto por producto.
    Livewire::test(Form::class)
        ->assertSet('trackLots', true)
        ->assertSet('trackExpiry', true)
        ->assertSet('trackSerials', false);
});

it('registra el inventario inicial con su movimiento de kardex', function () {
    Livewire::test(Form::class)
        ->set('name', 'Jarabe para la tos')
        ->set('sku', 'MED-002')
        ->set('cost', 25)
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('initialStock', 40)
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::firstWhere('sku', 'MED-002');

    expect(Inventory::where('product_id', $product->id)->value('quantity'))->toBe(40.0);

    // Ninguna cantidad debe existir sin un movimiento que la explique.
    $movement = InventoryMovement::where('product_id', $product->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe('initial')
        ->and($movement->quantity)->toBe(40.0)
        ->and($movement->balance)->toBe(40.0);
});

it('guarda varias presentaciones con sus equivalencias', function () {
    $caja = Unit::where('code', 'CJA')->first();
    $docena = Unit::where('code', 'DOC')->first();

    Livewire::test(Form::class)
        ->set('name', 'Guantes de latex')
        ->set('sku', 'INS-001')
        ->set('cost', 2)
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('unitRows', [
            ['unit_id' => $this->unit->id, 'factor' => 1, 'is_default' => true, 'barcode' => '111'],
            ['unit_id' => $docena->id, 'factor' => 12, 'is_default' => false, 'barcode' => '222'],
            ['unit_id' => $caja->id, 'factor' => 144, 'is_default' => false, 'barcode' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::firstWhere('sku', 'INS-001');

    expect($product->units)->toHaveCount(3)
        ->and($product->units->firstWhere('factor', 144)->unit_id)->toBe($caja->id);

    // Cada presentacion lleva su propio codigo de barra.
    expect(ProductBarcode::where('product_id', $product->id)->count())->toBe(2);
});

it('exige exactamente una presentacion predeterminada', function () {
    $docena = Unit::where('code', 'DOC')->first();

    Livewire::test(Form::class)
        ->set('name', 'Producto')
        ->set('sku', 'X-1')
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('unitRows', [
            ['unit_id' => $this->unit->id, 'factor' => 1, 'is_default' => true, 'barcode' => ''],
            ['unit_id' => $docena->id, 'factor' => 12, 'is_default' => true, 'barcode' => ''],
        ])
        ->call('save')
        ->assertHasErrors('unitRows');

    expect(Product::count())->toBe(0);
});

it('rechaza una unidad repetida entre presentaciones', function () {
    Livewire::test(Form::class)
        ->set('name', 'Producto')
        ->set('sku', 'X-2')
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('unitRows', [
            ['unit_id' => $this->unit->id, 'factor' => 1, 'is_default' => true, 'barcode' => ''],
            ['unit_id' => $this->unit->id, 'factor' => 12, 'is_default' => false, 'barcode' => ''],
        ])
        ->call('save')
        ->assertHasErrors('unitRows');
});

it('enciende lotes solo al activar vencimiento', function () {
    // No tiene sentido controlar vencimiento sin un lote que lo
    // identifique, asi que la pantalla lo resuelve sin preguntar.
    Livewire::test(Form::class)
        ->set('trackLots', false)
        ->set('trackExpiry', true)
        ->assertSet('trackLots', true);
});

it('apaga el vencimiento al quitar los lotes', function () {
    Livewire::test(Form::class)
        ->set('trackExpiry', true)
        ->set('trackLots', false)
        ->assertSet('trackExpiry', false);
});

it('apaga lotes y series si el producto no maneja stock', function () {
    Livewire::test(Form::class)
        ->set('trackLots', true)
        ->set('trackSerials', true)
        ->set('trackStock', false)
        ->assertSet('trackLots', false)
        ->assertSet('trackSerials', false);
});

it('no acepta un SKU repetido dentro de la misma empresa', function () {
    Livewire::test(Form::class)
        ->set('name', 'Primero')
        ->set('sku', 'DUP-1')
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(Form::class)
        ->set('name', 'Segundo')
        ->set('sku', 'DUP-1')
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->call('save')
        ->assertHasErrors('sku');

    expect(Product::count())->toBe(1);
});

it('calcula el precio desde un margen deseado', function () {
    $publico = PriceList::where('name', 'Publico')->first();

    Livewire::test(Form::class)
        ->set('cost', 100)
        ->set('priceRows', [[
            'price_list_id' => $publico->id,
            'name' => 'Publico',
            'price' => 0,
            'margin' => null,
        ]])
        ->call('applyMargin', 0, 30)
        // costo 100 + 30% = 130 neto, + 15% de impuesto = 149.50
        ->assertSet('priceRows.0.price', 149.5)
        ->assertSet('priceRows.0.margin', 30.0);
});

it('deja constancia de los cambios de precio y de costo', function () {
    $publico = PriceList::where('name', 'Publico')->first();

    $component = Livewire::test(Form::class)
        ->set('name', 'Producto con historial')
        ->set('sku', 'HIST-1')
        ->set('cost', 10)
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('priceRows', [[
            'price_list_id' => $publico->id,
            'name' => 'Publico',
            'price' => 20.0,
            'margin' => null,
        ]])
        ->call('save');

    $product = Product::firstWhere('sku', 'HIST-1');

    expect(CostHistory::where('product_id', $product->id)->count())->toBe(1)
        ->and(PriceHistory::where('product_id', $product->id)->count())->toBe(1);

    // Al editar y subir el costo se agrega otro renglon al historial.
    Livewire::test(Form::class, ['productId' => $product->id])
        ->set('cost', 12)
        ->call('save')
        ->assertHasNoErrors();

    $history = CostHistory::where('product_id', $product->id)->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history->last()->old_cost)->toBe(10.0)
        ->and($history->last()->new_cost)->toBe(12.0);
});

it('no vuelve a sumar el inventario inicial al editar', function () {
    Livewire::test(Form::class)
        ->set('name', 'Producto')
        ->set('sku', 'EDIT-1')
        ->set('cost', 5)
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('initialStock', 10)
        ->call('save');

    $product = Product::firstWhere('sku', 'EDIT-1');

    Livewire::test(Form::class, ['productId' => $product->id])
        ->set('name', 'Producto renombrado')
        ->call('save')
        ->assertHasNoErrors();

    expect(Inventory::where('product_id', $product->id)->value('quantity'))->toBe(10.0);
});

it('desactiva las presentaciones que se quitan en lugar de borrarlas', function () {
    $docena = Unit::where('code', 'DOC')->first();

    Livewire::test(Form::class)
        ->set('name', 'Producto')
        ->set('sku', 'PRES-1')
        ->set('trackLots', false)
        ->set('trackExpiry', false)
        ->set('unitRows', [
            ['unit_id' => $this->unit->id, 'factor' => 1, 'is_default' => true, 'barcode' => ''],
            ['unit_id' => $docena->id, 'factor' => 12, 'is_default' => false, 'barcode' => ''],
        ])
        ->call('save');

    $product = Product::firstWhere('sku', 'PRES-1');

    Livewire::test(Form::class, ['productId' => $product->id])
        ->set('unitRows', [
            ['unit_id' => $this->unit->id, 'factor' => 1, 'is_default' => true, 'barcode' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors();

    // La fila sigue existiendo: puede estar referenciada por ventas viejas.
    expect(ProductUnit::where('product_id', $product->id)->count())->toBe(2)
        ->and(ProductUnit::where('product_id', $product->id)->where('status', 'active')->count())->toBe(1);
});
