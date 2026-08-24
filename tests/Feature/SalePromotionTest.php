<?php

use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Sale;
use App\Models\SaleItemPromotion;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;

/**
 * Las promociones se aplican al registrar la venta, no antes.
 *
 * El descuento se calcula del lado del servidor a partir de lo que hay
 * guardado: si viniera de la pantalla, cualquiera podria mandar el suyo.
 */
beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;

    $this->registrar = app(SaleRegistrar::class);
    $this->cash = PaymentMethod::where('code', 'EFE')->value('id');

    $this->product = Product::create([
        'sku' => 'B-1',
        'name' => 'Refresco',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 200, 'initial');

    $this->shift = app(CashRegister::class)->open(
        $this->context['setup']['terminal']->id,
        $this->branchId,
        0,
    );
});

function sellWith(float $qty, float $price, float $paid, array $extra = []): Sale
{
    return test()->registrar->register(
        shift: test()->shift,
        lines: [[
            'product_id' => test()->product->id,
            'quantity' => $qty,
            'unit_price' => $price,
            ...($extra['line'] ?? []),
        ]],
        payments: [['payment_method_id' => test()->cash, 'amount' => $paid]],
        customerId: $extra['customer_id'] ?? null,
    );
}

it('un 2x1 cobra la mitad de lo que se lleva', function () {
    Promotion::create([
        'name' => '2x1 en refrescos',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $sale = sellWith(4, 25, 50);

    // 4 a 25 son 100; el 2x1 regala 2, quedan 50.
    expect($sale->subtotal)->toBe(100.0)
        ->and($sale->discount)->toBe(50.0)
        ->and($sale->total)->toBe(50.0);
});

it('deja constancia de que promocion se aplico', function () {
    $promo = Promotion::create([
        'name' => '2x1 en refrescos',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $sale = sellWith(4, 25, 50);

    $applied = SaleItemPromotion::where('sale_item_id', $sale->items->first()->id)->get();

    expect($applied)->toHaveCount(1)
        ->and($applied->first()->label)->toBe('2x1 en refrescos')
        ->and($applied->first()->discount)->toBe(50.0)
        ->and($applied->first()->free_quantity)->toBe(2.0)
        ->and($promo->fresh()->times_used)->toBe(1);
});

it('el ticket guarda el nombre aunque la promocion cambie despues', function () {
    $promo = Promotion::create([
        'name' => 'Martes de 2x1',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $sale = sellWith(2, 25, 25);

    $promo->update(['name' => 'Promocion de octubre']);

    expect(SaleItemPromotion::where('sale_item_id', $sale->items->first()->id)->value('label'))
        ->toBe('Martes de 2x1');
});

it('cobra el precio de lista cuando la promocion no alcanza al producto', function () {
    $otro = Product::create([
        'sku' => 'B-2',
        'name' => 'Pan',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
    ]);

    $promo = Promotion::create([
        'name' => '2x1 en pan',
        'type' => 'nxm',
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    PromotionTarget::create([
        'promotion_id' => $promo->id,
        'target_type' => 'product',
        'target_id' => $otro->id,
    ]);

    $sale = sellWith(4, 25, 100);

    expect($sale->discount)->toBe(0.0)
        ->and($sale->total)->toBe(100.0);
});

it('no aplica una promocion vencida', function () {
    Promotion::create([
        'name' => 'La del mes pasado',
        'type' => 'percent',
        'applies_to_all' => true,
        'discount_percent' => 50,
        'ends_on' => now()->subDay()->toDateString(),
    ]);

    expect(sellWith(2, 25, 50)->discount)->toBe(0.0);
});

it('suma el descuento a mano al de la promocion', function () {
    Promotion::create([
        'name' => '10% menos',
        'type' => 'percent',
        'applies_to_all' => true,
        'discount_percent' => 10,
    ]);

    // 100 de linea: 10 de promocion mas 15 que da el cajero.
    $sale = sellWith(4, 25, 75, ['line' => ['discount' => 15]]);

    expect($sale->discount)->toBe(25.0)
        ->and($sale->total)->toBe(75.0);
});

it('no deja la venta en negativo aunque todo se descuente', function () {
    Promotion::create([
        'name' => 'Todo gratis',
        'type' => 'percent',
        'applies_to_all' => true,
        'discount_percent' => 90,
    ]);

    // 100 de linea: 90 de promocion y 50 a mano suman mas que la linea.
    $sale = sellWith(4, 25, 0.01, ['line' => ['discount' => 50]]);

    expect($sale->discount)->toBe(100.0)
        ->and($sale->total)->toBe(0.0);
});

it('descuenta del inventario todo lo que se lleva, no solo lo cobrado', function () {
    Promotion::create([
        'name' => '2x1',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    sellWith(4, 25, 50);

    // Lo regalado sale del estante igual que lo cobrado.
    expect($this->product->fresh()->stock($this->branchId))->toBe(196.0);
});

it('la utilidad cuenta el costo de lo regalado', function () {
    Promotion::create([
        'name' => '2x1',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $sale = sellWith(4, 25, 50);

    // 4 unidades a costo 10 son 40, se hayan cobrado o no.
    expect($sale->cost_total)->toBe(40.0);
});

it('aplica la promocion de un tipo de cliente solo a ese tipo', function () {
    $tipo = CustomerType::first();

    $cliente = Customer::create(['name' => 'Cliente frecuente', 'customer_type_id' => $tipo->id]);
    $otro = Customer::create(['name' => 'Cliente de paso']);

    Promotion::create([
        'name' => 'Descuento de socio',
        'type' => 'percent',
        'applies_to_all' => true,
        'discount_percent' => 10,
        'customer_type_id' => $tipo->id,
    ]);

    expect(sellWith(4, 25, 90, ['customer_id' => $cliente->id])->discount)->toBe(10.0)
        ->and(sellWith(4, 25, 100, ['customer_id' => $otro->id])->discount)->toBe(0.0);
});

it('el impuesto se calcula sobre lo que de verdad se cobro', function () {
    Promotion::create([
        'name' => '2x1',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $this->product->update(['tax_id' => Tax::where('rate', 15)->value('id')]);

    $sale = sellWith(2, 115, 115);

    // Se cobran 115 con 15% adentro: 100 de neto y 15 de impuesto.
    // Declarar el impuesto de los 230 seria cobrarle al negocio un
    // impuesto que nunca recibio.
    expect($sale->total)->toBe(115.0)
        ->and($sale->tax)->toBe(15.0)
        ->and(round($sale->total - $sale->tax, 2))->toBe(100.0);
});
