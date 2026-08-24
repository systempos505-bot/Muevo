<?php

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Unit;
use App\Services\InventoryManager;

beforeEach(function () {
    $this->context = actingAsTenant('pharmacy');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->manager = app(InventoryManager::class);

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);
});

it('suma la entrada y deja su renglon de kardex', function () {
    $inventory = $this->manager->move($this->product, $this->branchId, 25, 'purchase', 'Compra inicial');

    expect($inventory->quantity)->toBe(25.0);

    $movement = InventoryMovement::where('product_id', $this->product->id)->sole();

    expect($movement->type)->toBe('purchase')
        ->and($movement->quantity)->toBe(25.0)
        ->and($movement->balance)->toBe(25.0)
        ->and($movement->reason)->toBe('Compra inicial');
});

it('acumula movimientos y arrastra el saldo', function () {
    $this->manager->move($this->product, $this->branchId, 100, 'purchase');
    $this->manager->move($this->product, $this->branchId, -30, 'sale');
    $final = $this->manager->move($this->product, $this->branchId, -20, 'sale');

    expect($final->quantity)->toBe(50.0);

    // El saldo de cada renglon permite leer el kardex sin recalcular
    // toda la historia hacia atras.
    $balances = InventoryMovement::where('product_id', $this->product->id)
        ->orderBy('id')
        ->pluck('balance')
        ->all();

    expect($balances)->toBe([100.0, 70.0, 50.0]);
});

it('deja que la existencia quede negativa', function () {
    // Vale mas registrar la venta y que el faltante se vea, a rechazarla
    // en el mostrador por un inventario mal capturado.
    $inventory = $this->manager->move($this->product, $this->branchId, -5, 'sale');

    expect($inventory->quantity)->toBe(-5.0);
});

it('rechaza un movimiento en cero', function () {
    $this->manager->move($this->product, $this->branchId, 0, 'adjustment');
})->throws(InvalidArgumentException::class, 'no puede ser cero');

it('rechaza mover un producto que no maneja stock', function () {
    $service = Product::create([
        'sku' => 'SERV-1',
        'name' => 'Servicio',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'track_stock' => false,
    ]);

    $this->manager->move($service, $this->branchId, 5, 'adjustment');
})->throws(InvalidArgumentException::class, 'no maneja stock');

describe('costo promedio ponderado', function () {
    it('promedia el costo al entrar mercancia mas cara', function () {
        // 10 piezas a 10 y luego 10 a 20 dan un promedio de 15.
        $this->manager->move($this->product, $this->branchId, 10, 'purchase');

        $inventory = Inventory::where('product_id', $this->product->id)->first();
        $inventory->update(['avg_cost' => 10]);

        $this->product->update(['cost' => 20]);
        $this->manager->move($this->product->fresh(), $this->branchId, 10, 'purchase');

        expect(Inventory::where('product_id', $this->product->id)->value('avg_cost'))->toBe(15.0);
    });

    it('no cambia el costo promedio en una salida', function () {
        $this->manager->move($this->product, $this->branchId, 10, 'purchase');
        $before = Inventory::where('product_id', $this->product->id)->value('avg_cost');

        $this->manager->move($this->product, $this->branchId, -4, 'sale');

        expect(Inventory::where('product_id', $this->product->id)->value('avg_cost'))->toBe($before);
    });
});

describe('setQuantity', function () {
    it('calcula la diferencia para dejar la cantidad contada', function () {
        $this->manager->move($this->product, $this->branchId, 50, 'purchase');

        $this->manager->setQuantity($this->product, $this->branchId, 47, 'Conteo fisico');

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(47.0);

        $last = InventoryMovement::where('product_id', $this->product->id)->latest('id')->first();

        expect($last->type)->toBe('count')
            ->and($last->quantity)->toBe(-3.0)
            ->and($last->balance)->toBe(47.0);
    });

    it('no registra nada si lo contado coincide con el sistema', function () {
        $this->manager->move($this->product, $this->branchId, 50, 'purchase');

        $result = $this->manager->setQuantity($this->product, $this->branchId, 50, 'Conteo');

        expect($result)->toBeNull()
            ->and(InventoryMovement::where('product_id', $this->product->id)->count())->toBe(1);
    });
});

describe('lotes', function () {
    beforeEach(function () {
        $this->product->update(['track_lots' => true, 'track_expiry' => true]);
    });

    it('crea el lote y suma su cantidad', function () {
        $this->manager->receiveLot(
            product: $this->product,
            branchId: $this->branchId,
            lotNumber: 'L-001',
            quantity: 40,
            expiryDate: '2027-06-30',
            cost: 12,
        );

        $lot = ProductLot::where('product_id', $this->product->id)->sole();

        expect($lot->lot_number)->toBe('L-001')
            ->and($lot->quantity)->toBe(40.0)
            ->and($lot->expiry_date->format('Y-m-d'))->toBe('2027-06-30');

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(40.0);
    });

    it('acumula sobre un lote que ya existe', function () {
        $this->manager->receiveLot($this->product, $this->branchId, 'L-001', 40, '2027-06-30');
        $this->manager->receiveLot($this->product, $this->branchId, 'L-001', 10, '2027-06-30');

        expect(ProductLot::where('product_id', $this->product->id)->count())->toBe(1)
            ->and(ProductLot::where('product_id', $this->product->id)->value('quantity'))->toBe(50.0);
    });

    it('exige fecha de vencimiento cuando el producto la controla', function () {
        $this->manager->receiveLot($this->product, $this->branchId, 'L-002', 10);
    })->throws(InvalidArgumentException::class, 'exige fecha de vencimiento');

    it('marca el lote como agotado al consumirlo entero', function () {
        $this->manager->receiveLot($this->product, $this->branchId, 'L-001', 10, '2027-06-30');

        $lot = ProductLot::where('product_id', $this->product->id)->sole();
        $this->manager->move($this->product, $this->branchId, -10, 'sale', lot: $lot);

        expect($lot->fresh()->status)->toBe('depleted')
            ->and($lot->fresh()->quantity)->toBe(0.0);
    });

    it('rechaza lotes en un producto que no los maneja', function () {
        $this->product->update(['track_expiry' => false, 'track_lots' => false]);

        $this->manager->receiveLot($this->product->fresh(), $this->branchId, 'L-1', 5);
    })->throws(InvalidArgumentException::class, 'no maneja lotes');
});
