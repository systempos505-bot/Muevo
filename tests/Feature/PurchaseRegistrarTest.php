<?php

use App\Models\CostHistory;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\InventoryManager;
use App\Services\PurchaseRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant('hardware');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->registrar = app(PurchaseRegistrar::class);

    $this->supplier = Supplier::create(['name' => 'Distribuidora Central']);

    $this->product = Product::create([
        'sku' => 'FER-1',
        'name' => 'Martillo',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'tax_id' => Tax::where('is_default', true)->value('id'),
        'cost' => 50,
    ]);
});

function purchaseLine(Product $product, float $qty, float $cost, array $extra = []): array
{
    return array_merge([
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_cost' => $cost,
    ], $extra);
}

// =============================================================
// Recepcion
// =============================================================

it('recibe la mercancia y la mete al inventario', function () {
    $purchase = $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 20, 45)],
        supplierId: $this->supplier->id,
    );

    expect($purchase->folio)->toStartWith('C-')
        ->and($purchase->status)->toBe('received')
        // 20 x 45 = 900 sin impuesto, +15% = 1035
        ->and($purchase->subtotal)->toBe(900.0)
        ->and($purchase->tax)->toBe(135.0)
        ->and($purchase->total)->toBe(1035.0);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(20.0);

    $movement = InventoryMovement::where('reference_id', $purchase->id)->sole();
    expect($movement->type)->toBe('purchase')
        ->and($movement->quantity)->toBe(20.0);
});

it('actualiza el costo del producto y lo deja registrado', function () {
    $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 10, 62)],
        supplierId: $this->supplier->id,
    );

    expect($this->product->fresh()->cost)->toBe(62.0);

    $history = CostHistory::where('product_id', $this->product->id)->latest('id')->first();

    expect($history->old_cost)->toBe(50.0)
        ->and($history->new_cost)->toBe(62.0)
        ->and($history->source)->toBe('purchase');
});

it('respeta el costo actual si se pide no actualizarlo', function () {
    $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 10, 62)],
        supplierId: $this->supplier->id,
        updatesCost: false,
    );

    expect($this->product->fresh()->cost)->toBe(50.0);
});

it('calcula el costo por pieza al comprar por caja', function () {
    $caja = Unit::where('code', 'CJA')->first();

    ProductUnit::create([
        'product_id' => $this->product->id,
        'unit_id' => $this->product->base_unit_id,
        'factor' => 1,
        'is_default' => true,
    ]);

    $boxUnit = ProductUnit::create([
        'product_id' => $this->product->id,
        'unit_id' => $caja->id,
        'factor' => 24,
        'is_purchase' => true,
    ]);

    // 2 cajas de 24 a 240 cada una: 48 piezas a 10 cada una.
    $purchase = $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 2, 240, ['product_unit_id' => $boxUnit->id])],
        supplierId: $this->supplier->id,
    );

    $item = $purchase->items->first();

    expect($item->quantity)->toBe(2.0)
        ->and($item->base_quantity)->toBe(48.0)
        ->and($item->unit_cost)->toBe(240.0)
        ->and($item->base_unit_cost)->toBe(10.0);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(48.0)
        ->and($this->product->fresh()->cost)->toBe(10.0);
});

it('descuenta antes de calcular el costo por pieza', function () {
    // 10 piezas a 50 = 500, menos 100 de descuento = 400, o sea 40 c/u.
    $purchase = $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 10, 50, ['discount' => 100])],
        supplierId: $this->supplier->id,
    );

    expect($purchase->items->first()->base_unit_cost)->toBe(40.0)
        ->and($this->product->fresh()->cost)->toBe(40.0);
});

it('rechaza una compra sin productos', function () {
    $this->registrar->register(branchId: $this->branchId, lines: []);
})->throws(RuntimeException::class, 'no tiene productos');

it('rechaza un costo negativo', function () {
    $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 5, -10)],
    );
})->throws(RuntimeException::class, 'no puede ser negativo');

it('no deja la compra a medias si algo falla', function () {
    $otro = Product::create([
        'sku' => 'FER-2',
        'name' => 'Producto con lote',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'track_lots' => true,
    ]);

    try {
        $this->registrar->register(
            branchId: $this->branchId,
            lines: [
                purchaseLine($this->product, 10, 45),
                purchaseLine($otro, 5, 20),  // sin lote: falla
            ],
            supplierId: $this->supplier->id,
        );
    } catch (RuntimeException) {
        // Se ignora: lo que importa es que no quedo rastro.
    }

    expect(Purchase::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

// =============================================================
// Precios por margen
// =============================================================

it('sube el precio de venta cuando la lista trabaja por margen', function () {
    $lista = PriceList::where('name', 'Mayoreo')->first();
    $lista->update(['pricing_mode' => 'margin', 'margin_percent' => 40]);

    $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 10, 100)],
        supplierId: $this->supplier->id,
    );

    $price = ProductPrice::where('product_id', $this->product->id)
        ->where('price_list_id', $lista->id)
        ->first();

    // costo 100 + 40% = 140 neto, +15% de impuesto = 161
    expect($price->price)->toBe(161.0)
        ->and($price->is_manual)->toBeFalse();
});

it('no toca un precio capturado a mano', function () {
    $lista = PriceList::where('name', 'Mayoreo')->first();
    $lista->update(['pricing_mode' => 'margin', 'margin_percent' => 40]);

    ProductPrice::create([
        'product_id' => $this->product->id,
        'price_list_id' => $lista->id,
        'min_quantity' => 1,
        'price' => 199,
        'is_manual' => true,
    ]);

    $this->registrar->register(
        branchId: $this->branchId,
        lines: [purchaseLine($this->product, 10, 100)],
        supplierId: $this->supplier->id,
    );

    // Si alguien fijo el precio a proposito, el costo no lo sobrescribe.
    expect(ProductPrice::where('product_id', $this->product->id)
        ->where('price_list_id', $lista->id)
        ->value('price'))->toBe(199.0);
});

// =============================================================
// Lotes
// =============================================================

describe('lotes', function () {
    beforeEach(function () {
        $this->product->update(['track_lots' => true, 'track_expiry' => true]);
    });

    it('crea el lote con su vencimiento', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 30, 45, [
                'lot_number' => 'L-2027-A',
                'expiry_date' => '2027-08-31',
            ])],
            supplierId: $this->supplier->id,
        );

        $lot = ProductLot::where('product_id', $this->product->id)->sole();

        expect($lot->lot_number)->toBe('L-2027-A')
            ->and($lot->quantity)->toBe(30.0)
            ->and($lot->expiry_date->format('Y-m-d'))->toBe('2027-08-31');

        expect($purchase->items->first()->lot_id)->toBe($lot->id);
    });

    it('exige numero de lote', function () {
        $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 10, 45, ['expiry_date' => '2027-08-31'])],
        );
    })->throws(RuntimeException::class, 'necesita numero de lote');

    it('exige fecha de vencimiento', function () {
        $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 10, 45, ['lot_number' => 'L-1'])],
        );
    })->throws(RuntimeException::class, 'necesita fecha de vencimiento');
});

// =============================================================
// Credito y pagos
// =============================================================

describe('credito', function () {
    it('suma la deuda al proveedor', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
            dueDate: '2026-12-31',
        );

        expect($purchase->paid)->toBe(0.0)
            ->and($purchase->balance())->toBe(1035.0)
            ->and($this->supplier->fresh()->balance)->toBe(1035.0);
    });

    it('nace saldada si es de contado', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'cash',
        );

        expect($purchase->isPaid())->toBeTrue()
            ->and($this->supplier->fresh()->balance)->toBe(0.0)
            ->and(SupplierPayment::where('purchase_id', $purchase->id)->count())->toBe(1);
    });

    it('rechaza credito sin proveedor', function () {
        $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 5, 45)],
            paymentType: 'credit',
        );
    })->throws(RuntimeException::class, 'necesita un proveedor');

    it('abona y baja el saldo del proveedor', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
        );

        $this->registrar->pay($purchase, 500);

        expect($purchase->fresh()->paid)->toBe(500.0)
            ->and($purchase->fresh()->balance())->toBe(535.0)
            ->and($this->supplier->fresh()->balance)->toBe(535.0);
    });

    it('marca la compra como saldada al abonar el resto', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
        );

        $this->registrar->pay($purchase, 1035);

        expect($purchase->fresh()->isPaid())->toBeTrue()
            ->and($this->supplier->fresh()->balance)->toBe(0.0);
    });

    it('no deja abonar mas que el saldo', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
        );

        $this->registrar->pay($purchase, 2000);
    })->throws(RuntimeException::class, 'supera el saldo');

    it('detecta una compra vencida', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 5, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
            dueDate: now()->subWeek()->toDateString(),
        );

        expect($purchase->isOverdue())->toBeTrue();
    });
});

// =============================================================
// Anulacion
// =============================================================

describe('anulacion', function () {
    it('saca la mercancia y quita la deuda', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
        );

        $this->registrar->cancel($purchase, 'Mercancia equivocada');

        expect($purchase->fresh()->status)->toBe('cancelled')
            ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(0.0)
            ->and($this->supplier->fresh()->balance)->toBe(0.0);
    });

    it('se niega si ya se vendio parte de la mercancia', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 20, 45)],
            supplierId: $this->supplier->id,
        );

        // Se venden 5: anular dejaria el inventario en negativo.
        app(InventoryManager::class)->move($this->product, $this->branchId, -5, 'sale');

        $this->registrar->cancel($purchase, 'Intento tardio');
    })->throws(RuntimeException::class, 'Ya se vendio parte');

    it('no deja anular dos veces', function () {
        $purchase = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 5, 45)],
            supplierId: $this->supplier->id,
        );

        $this->registrar->cancel($purchase, 'Primera');
        $this->registrar->cancel($purchase->fresh(), 'Segunda');
    })->throws(RuntimeException::class, 'ya estaba anulada');
});

// =============================================================
// Folios
// =============================================================

it('numera las compras sin repetir folio', function () {
    $folios = [];

    for ($i = 0; $i < 3; $i++) {
        $folios[] = $this->registrar->register(
            branchId: $this->branchId,
            lines: [purchaseLine($this->product, 1, 45)],
            supplierId: $this->supplier->id,
        )->folio;
    }

    expect($folios)->toBe(['C-000001', 'C-000002', 'C-000003']);
});
