<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\Unit;
use App\Services\InventoryManager;
use App\Services\StockCountManager;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branch = $this->context['setup']['branch'];

    $this->counts = app(StockCountManager::class);
    $this->inventory = app(InventoryManager::class);

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

    $this->inventory->move($this->camisa, $this->branch->id, 10, 'initial');
    $this->inventory->move($this->pantalon, $this->branch->id, 5, 'initial');
});

function stockOf(Product $product, string $branchId): float
{
    return (float) (Inventory::where('branch_id', $branchId)
        ->where('product_id', $product->id)
        ->value('quantity') ?? 0);
}

// =============================================================
// Abrir
// =============================================================

describe('abrir un conteo', function () {
    it('trae el catalogo activo con la existencia actual', function () {
        $count = $this->counts->open($this->branch->id);

        expect($count->status)->toBe(StockCount::OPEN)
            ->and($count->folio)->toStartWith('INV-')
            ->and($count->items)->toHaveCount(2);

        $linea = $count->items->firstWhere('product_id', $this->camisa->id);
        expect($linea->system_qty)->toBe(10.0)
            ->and($linea->counted_qty)->toBe(10.0);
    });

    it('no mueve nada al abrirse', function () {
        $this->counts->open($this->branch->id);

        expect(stockOf($this->camisa, $this->branch->id))->toBe(10.0)
            ->and(InventoryMovement::count())->toBe(2); // solo los 'initial' del setup
    });

    it('incluye lo que esta en cero, para poder detectar sobrantes', function () {
        $vacio = Product::create([
            'sku' => 'P-3',
            'name' => 'Zapato',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        $count = $this->counts->open($this->branch->id);

        $linea = $count->items->firstWhere('product_id', $vacio->id);
        expect($linea)->not->toBeNull()
            ->and($linea->system_qty)->toBe(0.0);
    });

    it('deja fuera lo que no maneja stock', function () {
        Product::create([
            'sku' => 'S-1',
            'name' => 'Sastreria a medida',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'track_stock' => false,
        ]);

        $count = $this->counts->open($this->branch->id);

        expect($count->items)->toHaveCount(2);
    });

    it('se puede filtrar por categoria', function () {
        $ropa = Category::create(['name' => 'Ropa']);
        $this->camisa->update(['category_id' => $ropa->id]);

        $count = $this->counts->open($this->branch->id, categoryId: $ropa->id);

        expect($count->items)->toHaveCount(1)
            ->and($count->items->first()->product_id)->toBe($this->camisa->id);
    });
});

// =============================================================
// Capturar avance
// =============================================================

describe('guardar avance', function () {
    it('guarda lo contado sin mover el inventario', function () {
        $count = $this->counts->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        $this->counts->saveProgress($count, [$item->id => 8]);

        expect($item->fresh()->counted_qty)->toBe(8.0)
            ->and(stockOf($this->camisa, $this->branch->id))->toBe(10.0);
    });

    it('no deja guardar avance en un conteo ya aplicado', function () {
        $count = $this->counts->open($this->branch->id);
        $item = $count->items->first();

        $this->counts->apply($count);

        expect(fn () => $this->counts->saveProgress($count, [$item->id => 1]))
            ->toThrow(RuntimeException::class, 'ya se aplico');
    });
});

// =============================================================
// Sumar un producto puntual
// =============================================================

describe('agregar un producto al conteo', function () {
    it('lo suma con la existencia actual', function () {
        $ropa = Category::create(['name' => 'Ropa']);
        $this->camisa->update(['category_id' => $ropa->id]);

        $count = $this->counts->open($this->branch->id, categoryId: $ropa->id);
        expect($count->items)->toHaveCount(1);

        $this->counts->addProduct($count, $this->pantalon->id);

        expect($count->fresh('items')->items)->toHaveCount(2);
    });

    it('no lo duplica si ya estaba', function () {
        $count = $this->counts->open($this->branch->id);

        $this->counts->addProduct($count, $this->camisa->id);

        expect($count->fresh('items')->items)->toHaveCount(2);
    });

    it('rechaza un producto que no maneja stock', function () {
        $servicio = Product::create([
            'sku' => 'S-1',
            'name' => 'Instalacion',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'track_stock' => false,
        ]);

        $count = $this->counts->open($this->branch->id);

        expect(fn () => $this->counts->addProduct($count, $servicio->id))
            ->toThrow(RuntimeException::class, 'no maneja stock');
    });
});

// =============================================================
// Aplicar
// =============================================================

describe('aplicar', function () {
    it('ajusta solo las lineas que quedaron distintas', function () {
        $count = $this->counts->open($this->branch->id);
        $camisaItem = $count->items->firstWhere('product_id', $this->camisa->id);

        $this->counts->saveProgress($count, [$camisaItem->id => 7]);
        $this->counts->apply($count->fresh());

        // La camisa cambio de 10 a 7; el pantalon no se toco y sigue en 5.
        expect(stockOf($this->camisa, $this->branch->id))->toBe(7.0)
            ->and(stockOf($this->pantalon, $this->branch->id))->toBe(5.0);
    });

    it('no deja movimiento en las lineas sin diferencia', function () {
        $before = InventoryMovement::count();

        $count = $this->counts->open($this->branch->id);
        $this->counts->apply($count);

        // Nadie cambio ninguna cantidad: aplicar no debe generar ni un
        // movimiento, o el kardex se llenaria de ajustes en cero.
        expect(InventoryMovement::count())->toBe($before);
    });

    it('marca el conteo como aplicado', function () {
        $count = $this->counts->open($this->branch->id);

        $this->counts->apply($count);

        expect($count->fresh()->status)->toBe(StockCount::APPLIED)
            ->and($count->fresh()->applied_at)->not->toBeNull();
    });

    it('calcula la diferencia contra la existencia actual, no contra la de cuando se abrio', function () {
        $count = $this->counts->open($this->branch->id);
        $camisaItem = $count->items->firstWhere('product_id', $this->camisa->id);

        // Mientras se contaba, entraron 3 camisas mas por una compra.
        $this->inventory->move($this->camisa, $this->branch->id, 3, 'purchase');

        // El que conto encontro 13 en el piso (10 originales + 3 nuevas).
        $this->counts->saveProgress($count, [$camisaItem->id => 13]);
        $this->counts->apply($count->fresh());

        // Si se hubiera aplicado contra el system_qty viejo (10), el
        // ajuste habria sumado 3 de mas y quedaria en 16 en vez de 13.
        expect(stockOf($this->camisa, $this->branch->id))->toBe(13.0);
    });

    it('no se puede aplicar dos veces', function () {
        $count = $this->counts->open($this->branch->id);
        $this->counts->apply($count);

        expect(fn () => $this->counts->apply($count->fresh()))
            ->toThrow(RuntimeException::class, 'ya se aplico');
    });

    it('deja el movimiento ligado al conteo', function () {
        $count = $this->counts->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);

        $this->counts->saveProgress($count, [$item->id => 6]);
        $this->counts->apply($count->fresh());

        $movement = InventoryMovement::where('product_id', $this->camisa->id)
            ->where('type', 'count')
            ->latest('created_at')
            ->first();

        expect($movement->reference_type)->toBe('stock_count')
            ->and($movement->reference_id)->toBe($count->id);
    });
});

// =============================================================
// Cancelar
// =============================================================

describe('cancelar', function () {
    it('no mueve nada', function () {
        $count = $this->counts->open($this->branch->id);
        $item = $count->items->firstWhere('product_id', $this->camisa->id);
        $this->counts->saveProgress($count, [$item->id => 999]);

        $this->counts->cancel($count->fresh());

        expect(stockOf($this->camisa, $this->branch->id))->toBe(10.0)
            ->and($count->fresh()->status)->toBe(StockCount::CANCELLED);
    });

    it('no se puede cancelar uno ya aplicado', function () {
        $count = $this->counts->open($this->branch->id);
        $this->counts->apply($count);

        expect(fn () => $this->counts->cancel($count->fresh()))
            ->toThrow(RuntimeException::class, 'ya se aplico');
    });
});
