<?php

use App\Livewire\Inventory\Counts\Index;
use App\Livewire\Inventory\Counts\Show;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\Unit;
use App\Services\InventoryManager;
use App\Services\StockCountManager;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branch = $this->context['setup']['branch'];

    $this->camisa = Product::create([
        'sku' => 'P-1',
        'name' => 'Camisa',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 100,
    ]);

    $this->pantalon = Product::create([
        'sku' => 'P-2',
        'name' => 'Pantalon',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 200,
    ]);

    app(InventoryManager::class)->move($this->camisa, $this->branch->id, 10, 'initial');
    app(InventoryManager::class)->move($this->pantalon, $this->branch->id, 5, 'initial');
});

function stockOfProduct(Product $product, string $branchId): float
{
    return (float) (Inventory::where('branch_id', $branchId)
        ->where('product_id', $product->id)
        ->value('quantity') ?? 0);
}

describe('acceso', function () {
    it('niega la pantalla a quien no ve inventario', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'inventory.view' => false],
        ]);

        $this->get(route('stock-counts'))->assertForbidden();
    });

    it('deja mirar pero no abrir un conteo a quien solo puede ver', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'inventory.view' => true],
        ]);

        $this->get(route('stock-counts'))->assertOk()->assertDontSee('+ Conteo');

        Livewire::test(Index::class)->call('create')->assertForbidden();
    });

    it('deja mirar el detalle pero no capturar a quien solo puede ver', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);

        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'inventory.view' => true],
        ]);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->call('saveProgress')
            ->assertForbidden();
    });
});

describe('alta', function () {
    it('abre el conteo con todo el catalogo y lleva al detalle', function () {
        Livewire::test(Index::class)
            ->call('create')
            ->set('branchId', $this->branch->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('stock-counts.show', StockCount::first()));

        expect(StockCount::first()->items)->toHaveCount(2);
    });

    it('filtra por categoria', function () {
        $ropa = Category::create(['name' => 'Ropa']);
        $this->camisa->update(['category_id' => $ropa->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->set('branchId', $this->branch->id)
            ->set('categoryId', $ropa->id)
            ->call('save');

        expect(StockCount::first()->items)->toHaveCount(1);
    });

    it('exige una sucursal', function () {
        Livewire::test(Index::class)
            ->call('create')
            ->set('branchId', '')
            ->call('save')
            ->assertHasErrors(['branchId']);
    });
});

describe('captura y aplicacion', function () {
    it('guarda el avance sin mover el inventario', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->set("counted.{$item->id}", 8)
            ->call('saveProgress')
            ->assertHasNoErrors();

        expect($item->fresh()->counted_qty)->toBe(8.0)
            ->and(stockOfProduct($this->camisa, $this->branch->id))->toBe(10.0);
    });

    it('aplica y ajusta solo las lineas con diferencia', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->set("counted.{$item->id}", 7)
            ->call('apply')
            ->assertHasNoErrors();

        expect(stockOfProduct($this->camisa, $this->branch->id))->toBe(7.0)
            ->and(stockOfProduct($this->pantalon, $this->branch->id))->toBe(5.0)
            ->and($count->fresh()->status)->toBe(StockCount::APPLIED);
    });

    it('el resumen en pantalla refleja lo que se esta escribiendo, antes de guardar', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        $component = Livewire::test(Show::class, ['countId' => $count->id])
            ->set("counted.{$item->id}", 8);

        // La camisa cuesta 100 y faltaron 2: 200 de faltante a costo,
        // visible antes de tocar "Guardar avance".
        expect($component->instance()->summary['shortage'])->toBe(200.0)
            ->and($component->instance()->summary['differences'])->toBe(1);
    });

    it('cancela sin mover nada', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->set("counted.{$item->id}", 999)
            ->call('cancel')
            ->assertHasNoErrors();

        expect(stockOfProduct($this->camisa, $this->branch->id))->toBe(10.0)
            ->and($count->fresh()->status)->toBe(StockCount::CANCELLED);
    });

    it('no ofrece capturar en un conteo ya aplicado', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);
        app(StockCountManager::class)->apply($count);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->assertDontSee('Guardar avance')
            ->assertDontSee('Aplicar conteo');
    });
});

describe('sumar un producto puntual', function () {
    it('lo agrega a la lista', function () {
        $ropa = Category::create(['name' => 'Ropa']);
        $this->camisa->update(['category_id' => $ropa->id]);

        $count = app(StockCountManager::class)->open($this->branch->id, categoryId: $ropa->id);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->set('productSearch', 'Pantalon')
            ->call('addProduct', $this->pantalon->id)
            ->assertSee('Pantalon');

        expect($count->fresh('items')->items)->toHaveCount(2);
    });
});

describe('busqueda dentro del conteo', function () {
    it('filtra las lineas visibles por nombre', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);

        Livewire::test(Show::class, ['countId' => $count->id])
            ->set('search', 'camisa')
            ->assertSee('Camisa')
            ->assertDontSee('Pantalon');
    });
});

describe('listado', function () {
    it('muestra el estado de cada conteo', function () {
        $count = app(StockCountManager::class)->open($this->branch->id);

        Livewire::test(Index::class)->assertSee($count->folio)->assertSee('Abierto');
    });
});
