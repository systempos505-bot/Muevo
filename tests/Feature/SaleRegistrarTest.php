<?php

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\Pricing;
use App\Services\SaleRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant('pharmacy');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->inventory = app(InventoryManager::class);
    $this->registrar = app(SaleRegistrar::class);
    $this->cash = app(CashRegister::class);

    $this->shift = $this->cash->open($this->terminalId, $this->branchId, 500);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->cardMethod = PaymentMethod::where('code', 'TAR')->first();
    $this->creditMethod = PaymentMethod::where('code', 'CRE')->first();

    // Producto con IVA 15% incluido en el precio: costo 100, precio 149.50
    $this->product = Product::create([
        'sku' => 'MED-1',
        'name' => 'Acetaminofen',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'tax_id' => Tax::where('is_default', true)->value('id'),
        'cost' => 100,
    ]);

    $this->inventory->move($this->product, $this->branchId, 50, 'initial', 'Carga inicial');
});

function line(Product $product, float $qty, float $price, float $discount = 0): array
{
    return [
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => $price,
        'discount' => $discount,
    ];
}

// =============================================================
// Venta basica
// =============================================================

it('registra una venta y descuenta el inventario', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 2, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 299]],
    );

    expect($sale->total)->toBe(299.0)
        ->and($sale->tax)->toBe(39.0)   // 299 - (299 / 1.15)
        ->and($sale->change)->toBe(0.0)
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->folio)->toStartWith('V-');

    // Neto + impuesto tiene que dar el total cobrado, al centavo.
    expect(Pricing::round($sale->items->sum('net') + $sale->tax, 2))->toBe($sale->total);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(48.0);

    $movement = InventoryMovement::where('reference_id', $sale->id)->sole();
    expect($movement->type)->toBe('sale')
        ->and($movement->quantity)->toBe(-2.0)
        ->and($movement->balance)->toBe(48.0);
});

it('calcula el cambio cuando se paga de mas en efectivo', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 1, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 200]],
    );

    expect($sale->total)->toBe(149.5)
        ->and($sale->paid)->toBe(200.0)
        ->and($sale->change)->toBe(50.5);
});

it('no da cambio cuando el excedente vino con tarjeta', function () {
    // Pagar de mas con tarjeta no genera efectivo que devolver.
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 1, 149.50)],
        payments: [['payment_method_id' => $this->cardMethod->id, 'amount' => 160]],
    );

    expect($sale->change)->toBe(0.0);
});

it('acepta pagos combinados', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 2, 149.50)],
        payments: [
            ['payment_method_id' => $this->cardMethod->id, 'amount' => 200],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => 99],
        ],
    );

    expect($sale->payments)->toHaveCount(2)
        ->and($sale->paid)->toBe(299.0)
        ->and($sale->change)->toBe(0.0);
});

it('aplica descuento por linea antes del impuesto', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 2, 149.50, discount: 49)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 250]],
    );

    expect($sale->subtotal)->toBe(299.0)
        ->and($sale->discount)->toBe(49.0)
        ->and($sale->total)->toBe(250.0)
        ->and(Pricing::round($sale->items->sum('net') + $sale->tax, 2))->toBe(250.0);
});

it('guarda el costo para poder calcular la utilidad despues', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 2, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 299]],
    );

    // Costo 100 x 2 = 200. Neto cobrado = 299 - 39 = 260. Utilidad = 60.
    expect($sale->cost_total)->toBe(200.0)
        ->and($sale->profit())->toBe(60.0);

    // Aunque el costo del producto cambie despues, el ticket no se mueve.
    $this->product->update(['cost' => 130]);
    expect($sale->fresh()->profit())->toBe(60.0);
});

it('conserva el nombre del producto tal como estaba al vender', function () {
    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 1, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 150]],
    );

    $this->product->update(['name' => 'Otro nombre completamente distinto']);

    expect($sale->items->first()->description)->toBe('Acetaminofen');
});

// =============================================================
// Presentaciones
// =============================================================

it('descuenta la unidad base al vender por caja', function () {
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
    ]);

    $sale = $this->registrar->register(
        shift: $this->shift,
        lines: [[
            'product_id' => $this->product->id,
            'product_unit_id' => $boxUnit->id,
            'quantity' => 1,
            'unit_price' => 3450,
        ]],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 3450]],
    );

    $item = $sale->items->first();

    // Se vendio 1 caja, pero el inventario baja 24 piezas.
    expect($item->quantity)->toBe(1.0)
        ->and($item->base_quantity)->toBe(24.0)
        ->and($item->unit_factor)->toBe(24.0);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(26.0);
});

// =============================================================
// Lotes y vencimiento
// =============================================================

describe('lotes', function () {
    beforeEach(function () {
        $this->product->update(['track_lots' => true, 'track_expiry' => true]);
        Inventory::where('product_id', $this->product->id)->delete();
        InventoryMovement::where('product_id', $this->product->id)->delete();

        // El que vence antes se carga despues, a proposito: el orden de
        // salida lo decide la fecha, no el orden de captura.
        $this->inventory->receiveLot($this->product, $this->branchId, 'L-TARDE', 10, '2030-12-31');
        $this->inventory->receiveLot($this->product, $this->branchId, 'L-PRONTO', 6, '2027-01-31');
    });

    it('consume primero el lote que vence antes', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 4, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 598]],
        );

        expect(ProductLot::where('lot_number', 'L-PRONTO')->value('quantity'))->toBe(2.0)
            ->and(ProductLot::where('lot_number', 'L-TARDE')->value('quantity'))->toBe(10.0);
    });

    it('reparte entre lotes cuando el primero no alcanza', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 9, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 1345.5]],
        );

        expect(ProductLot::where('lot_number', 'L-PRONTO')->value('quantity'))->toBe(0.0)
            ->and(ProductLot::where('lot_number', 'L-TARDE')->value('quantity'))->toBe(7.0);

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(7.0);
    });

    it('salta un lote vencido y toma del siguiente', function () {
        ProductLot::where('lot_number', 'L-PRONTO')->update(['expiry_date' => now()->subDay()]);

        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 3, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 448.5]],
        );

        // El vencido queda intacto; sale del que si se puede vender.
        expect(ProductLot::where('lot_number', 'L-PRONTO')->value('quantity'))->toBe(6.0)
            ->and(ProductLot::where('lot_number', 'L-TARDE')->value('quantity'))->toBe(7.0);
    });

    it('vende aunque los lotes no alcancen, dejando el faltante visible', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 20, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 2990]],
        );

        // En el mostrador es peor no poder cobrar que quedar con faltante.
        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(-4.0);
    });
});

// =============================================================
// Reglas de cobro
// =============================================================

it('rechaza una venta que no cubre el total', function () {
    $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 2, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 100]],
    );
})->throws(RuntimeException::class, 'no cubre el total');

it('rechaza una venta sin productos', function () {
    $this->registrar->register(
        shift: $this->shift,
        lines: [],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 100]],
    );
})->throws(RuntimeException::class, 'no tiene productos');

it('rechaza una venta sin forma de pago', function () {
    $this->registrar->register(
        shift: $this->shift,
        lines: [line($this->product, 1, 149.50)],
        payments: [],
    );
})->throws(RuntimeException::class, 'forma de pago');

it('rechaza vender con el turno cerrado', function () {
    $this->cash->close($this->shift, 500);

    $this->registrar->register(
        shift: $this->shift->fresh(),
        lines: [line($this->product, 1, 149.50)],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 150]],
    );
})->throws(RuntimeException::class, 'turno de caja abierto');

it('no deja la venta a medias si algo falla', function () {
    $before = Inventory::where('product_id', $this->product->id)->value('quantity');

    try {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 2, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 10]],
        );
    } catch (RuntimeException) {
        // Se ignora: lo que importa es que no quedo rastro.
    }

    expect(Sale::count())->toBe(0)
        ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe($before);
});

// =============================================================
// Credito
// =============================================================

describe('credito', function () {
    beforeEach(function () {
        $this->customer = Customer::create([
            'name' => 'Cliente con credito',
            'credit_enabled' => true,
            'credit_limit' => 1000,
        ]);
    });

    it('carga el saldo al cliente', function () {
        $sale = $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 2, 149.50)],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 299]],
            customerId: $this->customer->id,
        );

        expect($sale->total)->toBe(299.0)
            ->and($this->customer->fresh()->balance)->toBe(299.0);
    });

    it('acepta pago mixto de contado y credito', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 2, 149.50)],
            payments: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100],
                ['payment_method_id' => $this->creditMethod->id, 'amount' => 199],
            ],
            customerId: $this->customer->id,
        );

        expect($this->customer->fresh()->balance)->toBe(199.0);
    });

    it('respeta el limite de credito', function () {
        $this->customer->update(['balance' => 900]);

        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 2, 149.50)],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 299]],
            customerId: $this->customer->id,
        );
    })->throws(RuntimeException::class, 'limite de credito');

    it('rechaza credito a un cliente que no lo tiene autorizado', function () {
        $this->customer->update(['credit_enabled' => false]);

        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 1, 149.50)],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 149.5]],
            customerId: $this->customer->id,
        );
    })->throws(RuntimeException::class, 'no tiene credito autorizado');

    it('rechaza credito sin cliente identificado', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 1, 149.50)],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 149.5]],
        );
    })->throws(RuntimeException::class, 'necesita un cliente');
});

// =============================================================
// Folios
// =============================================================

it('numera las ventas sin repetir folio', function () {
    $folios = [];

    for ($i = 0; $i < 3; $i++) {
        $folios[] = $this->registrar->register(
            shift: $this->shift,
            lines: [line($this->product, 1, 149.50)],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 150]],
        )->folio;
    }

    expect($folios)->toBe(['V-000001', 'V-000002', 'V-000003'])
        ->and(array_unique($folios))->toHaveCount(3);
});
