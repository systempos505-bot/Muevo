<?php

use App\Livewire\Inventory\Index;
use App\Livewire\Inventory\Kardex;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Unit;
use App\Services\InventoryManager;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant('hardware');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->manager = app(InventoryManager::class);

    $this->product = Product::create([
        'sku' => 'FER-1',
        'name' => 'Martillo',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 50,
        'min_stock' => 5,
    ]);

    $this->manager->move($this->product, $this->branchId, 20, 'initial', 'Carga inicial de bodega');
});

describe('listado de existencias', function () {
    it('muestra el producto con su existencia', function () {
        Livewire::test(Index::class)
            ->assertSee('Martillo')
            ->assertSee('FER-1');
    });

    it('filtra los productos con stock bajo', function () {
        $low = Product::create([
            'sku' => 'FER-2',
            'name' => 'Desarmador',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'min_stock' => 10,
        ]);
        $this->manager->move($low, $this->branchId, 3, 'initial');

        Livewire::test(Index::class)
            ->set('filter', 'low')
            ->assertSee('Desarmador')
            ->assertDontSee('Martillo');
    });

    it('filtra los productos agotados', function () {
        $out = Product::create([
            'sku' => 'FER-3',
            'name' => 'Llave inglesa',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);
        $this->manager->move($out, $this->branchId, 5, 'initial');
        $this->manager->move($out, $this->branchId, -5, 'sale');

        Livewire::test(Index::class)
            ->set('filter', 'out')
            ->assertSee('Llave inglesa')
            ->assertDontSee('Martillo');
    });

    it('busca por nombre y por SKU', function () {
        Livewire::test(Index::class)
            ->set('search', 'FER-1')
            ->assertSee('Martillo');

        Livewire::test(Index::class)
            ->set('search', 'inexistente')
            ->assertDontSee('Martillo');
    });
});

describe('ajuste de inventario', function () {
    it('suma una entrada y la deja en el kardex', function () {
        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->assertSet('adjustCurrent', 20.0)
            ->set('adjustMode', 'delta')
            ->set('adjustQuantity', 5)
            ->set('adjustType', 'adjustment')
            ->set('adjustReason', 'Entrada por devolucion de cliente')
            ->call('saveAdjust')
            ->assertHasNoErrors()
            ->assertSet('showAdjust', false);

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(25.0);
    });

    it('resta una merma', function () {
        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->set('adjustQuantity', -3)
            ->set('adjustType', 'loss')
            ->set('adjustReason', 'Producto danado en bodega')
            ->call('saveAdjust')
            ->assertHasNoErrors();

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(17.0);

        $last = InventoryMovement::where('product_id', $this->product->id)->latest('id')->first();
        expect($last->type)->toBe('loss');
    });

    it('deja la existencia en la cantidad contada', function () {
        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->set('adjustMode', 'set')
            ->set('adjustQuantity', 18)
            ->set('adjustReason', 'Conteo fisico de fin de mes')
            ->call('saveAdjust')
            ->assertHasNoErrors();

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(18.0);

        $last = InventoryMovement::where('product_id', $this->product->id)->latest('id')->first();
        expect($last->type)->toBe('count')
            ->and($last->quantity)->toBe(-2.0);
    });

    it('exige un motivo que explique el ajuste', function () {
        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->set('adjustQuantity', 5)
            ->set('adjustReason', '')
            ->call('saveAdjust')
            ->assertHasErrors('adjustReason');

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(20.0);
    });

    it('rechaza un ajuste en cero', function () {
        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->set('adjustQuantity', 0)
            ->set('adjustReason', 'Sin cambio')
            ->call('saveAdjust')
            ->assertHasErrors('adjustQuantity');
    });

    it('no permite ajustar sin el permiso correspondiente', function () {
        // El rol de administrador trae el comodin, pero una excepcion
        // explicita sobre el usuario manda sobre el.
        $this->context['user']->update(['permissions_override' => ['inventory.adjust' => false]]);

        Livewire::test(Index::class)
            ->call('openAdjust', $this->product->id, $this->branchId)
            ->assertForbidden();
    });
});

describe('kardex', function () {
    it('lista los movimientos con su saldo', function () {
        $this->manager->move($this->product, $this->branchId, -5, 'sale', 'Venta de mostrador');

        Livewire::test(Kardex::class, ['productId' => $this->product->id])
            ->assertSee('Carga inicial de bodega')
            ->assertSee('Venta de mostrador');
    });

    it('filtra por tipo de movimiento', function () {
        $this->manager->move($this->product, $this->branchId, -5, 'sale', 'Venta de mostrador');

        Livewire::test(Kardex::class, ['productId' => $this->product->id])
            ->set('type', 'sale')
            ->assertSee('Venta de mostrador')
            ->assertDontSee('Carga inicial de bodega');
    });

    it('muestra la existencia actual del producto', function () {
        Livewire::test(Kardex::class, ['productId' => $this->product->id])
            ->assertViewHas('stock', 20.0);
    });
});
