<?php

use App\Livewire\Pos\CashDrawer;
use App\Livewire\Pos\Register;
use App\Livewire\Sales\Show;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Inventory;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant('supermarket');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;
    $this->publico = PriceList::where('is_default', true)->first();

    $this->product = Product::create([
        'sku' => 'ABA-1',
        'name' => 'Arroz 1kg',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 20,
    ]);

    ProductUnit::create([
        'product_id' => $this->product->id,
        'unit_id' => $this->product->base_unit_id,
        'factor' => 1,
        'is_default' => true,
    ]);

    ProductPrice::create([
        'product_id' => $this->product->id,
        'price_list_id' => $this->publico->id,
        'min_quantity' => 1,
        'price' => 30,
    ]);

    ProductBarcode::create([
        'product_id' => $this->product->id,
        'code' => '7501000000011',
        'is_primary' => true,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 100, 'initial');

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
});

// =============================================================
// Carrito
// =============================================================

describe('carrito', function () {
    it('agrega un producto al escanear su codigo de barra', function () {
        Livewire::test(Register::class)
            ->set('search', '7501000000011')
            ->call('submitSearch')
            ->assertCount('cart', 1)
            // La barra se limpia sola para poder escanear el siguiente.
            ->assertSet('search', '');
    });

    it('suma cantidad al escanear dos veces el mismo producto', function () {
        $component = Livewire::test(Register::class)
            ->set('search', '7501000000011')
            ->call('submitSearch')
            ->set('search', '7501000000011')
            ->call('submitSearch');

        expect($component->get('cart'))->toHaveCount(1);
        expect(array_values($component->get('cart'))[0]['quantity'])->toBe(2.0);
    });

    it('agrega el unico resultado al buscar por nombre', function () {
        Livewire::test(Register::class)
            ->set('search', 'Arroz')
            ->call('submitSearch')
            ->assertCount('cart', 1);
    });

    it('avisa cuando la busqueda no encuentra nada', function () {
        Livewire::test(Register::class)
            ->set('search', 'no-existe-nada')
            ->call('submitSearch')
            ->assertCount('cart', 0)
            ->assertDispatched('notify');
    });

    it('toma el precio de la lista activa', function () {
        $component = Livewire::test(Register::class)->call('addProduct', $this->product->id);

        expect(array_values($component->get('cart'))[0]['unit_price'])->toBe(30.0);
        expect($component->get('totals')['total'])->toBe(30.0);
    });

    it('sube y baja la cantidad', function () {
        $component = Livewire::test(Register::class)->call('addProduct', $this->product->id);
        $key = array_key_first($component->get('cart'));

        $component->call('increment', $key)->call('increment', $key);
        expect($component->get('totals')['total'])->toBe(90.0);

        $component->call('decrement', $key);
        expect($component->get('totals')['total'])->toBe(60.0);
    });

    it('quita la linea al bajar de uno', function () {
        $component = Livewire::test(Register::class)->call('addProduct', $this->product->id);
        $key = array_key_first($component->get('cart'));

        $component->call('decrement', $key)->assertCount('cart', 0);
    });

    it('vacia el carrito', function () {
        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('clearCart')
            ->assertCount('cart', 0);
    });

    it('aplica el precio por volumen al subir la cantidad', function () {
        // A partir de 10 unidades el precio baja a 25.
        ProductPrice::create([
            'product_id' => $this->product->id,
            'price_list_id' => $this->publico->id,
            'min_quantity' => 10,
            'price' => 25,
        ]);

        $component = Livewire::test(Register::class)->call('addProduct', $this->product->id);
        $key = array_key_first($component->get('cart'));

        expect(array_values($component->get('cart'))[0]['unit_price'])->toBe(30.0);

        $component->set("cart.{$key}.quantity", 10);

        expect(array_values($component->get('cart'))[0]['unit_price'])->toBe(25.0);
        expect($component->get('totals')['total'])->toBe(250.0);
    });

    it('cambia de lista de precios al elegir un cliente mayorista', function () {
        $mayoreo = PriceList::where('name', 'Mayoreo')->first();

        ProductPrice::create([
            'product_id' => $this->product->id,
            'price_list_id' => $mayoreo->id,
            'min_quantity' => 1,
            'price' => 24,
        ]);

        $customer = Customer::create(['name' => 'Tienda Don Beto', 'price_list_id' => $mayoreo->id]);

        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->set('customerId', $customer->id);

        expect($component->get('priceListId'))->toBe($mayoreo->id);
        expect(array_values($component->get('cart'))[0]['unit_price'])->toBe(24.0);
    });
});

// =============================================================
// Cobro
// =============================================================

describe('cobro', function () {
    it('pide abrir la caja antes de cobrar', function () {
        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('openPayment')
            // Sin turno abierto no se puede cobrar: primero la caja.
            ->assertSet('showOpenShift', true)
            ->assertSet('showPayment', false);
    });

    it('no deja cobrar un carrito vacio', function () {
        Livewire::test(Register::class)
            ->call('openPayment')
            ->assertSet('showPayment', false)
            ->assertDispatched('notify');
    });

    it('abre la caja desde la pantalla de venta', function () {
        Livewire::test(Register::class)
            ->set('openingAmount', 500)
            ->call('openShift')
            ->assertHasNoErrors()
            ->assertSet('showOpenShift', false);

        expect(Shift::openFor($this->terminalId))->not->toBeNull();
    });

    it('registra la venta y descuenta el inventario', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('openPayment')
            ->assertSet('showPayment', true)
            // El efectivo llega con el total exacto ya escrito.
            ->set('payments', [$this->cashMethod->id => 50])
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertCount('cart', 0);

        $sale = Sale::sole();

        expect($sale->total)->toBe(30.0)
            ->and($sale->change)->toBe(20.0);

        expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(99.0);
    });

    it('propone el total exacto en efectivo al abrir el cobro', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('openPayment');

        expect($component->get('payments'))->toBe([$this->cashMethod->id => 30.0]);
    });

    it('muestra el error del motor cuando el pago no alcanza', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('openPayment')
            ->set('payments', [$this->cashMethod->id => 5])
            ->call('checkout')
            ->assertHasErrors('payments');

        expect(Sale::count())->toBe(0);
    });
});

// =============================================================
// Ventas en espera
// =============================================================

describe('ventas en espera', function () {
    it('guarda y recupera una venta', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->set('holdLabel', 'Senora del sombrero')
            ->call('hold')
            ->assertCount('cart', 0);

        expect(HeldSale::count())->toBe(1);

        $held = HeldSale::sole();
        expect($held->label)->toBe('Senora del sombrero')
            ->and($held->total)->toBe(30.0);

        $component->call('resume', $held->id)->assertCount('cart', 1);

        // Al retomarla deja de estar en espera.
        expect(HeldSale::count())->toBe(0);
    });

    it('no deja retomar si ya hay algo en el carrito', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('hold')
            ->call('addProduct', $this->product->id);

        $component->call('resume', HeldSale::sole()->id)->assertCount('cart', 1);

        expect(HeldSale::count())->toBe(1);
    });

    it('descarta una venta en espera', function () {
        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->call('hold')
            ->call('discardHeld', HeldSale::sole()->id);

        expect(HeldSale::count())->toBe(0);
    });
});

// =============================================================
// Caja
// =============================================================

describe('pantalla de caja', function () {
    it('muestra el arqueo del turno abierto', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        $component = Livewire::test(CashDrawer::class);

        expect($component->get('summary')['opening'])->toBe(500.0)
            ->and($component->get('summary')['expected'])->toBe(500.0);
    });

    it('registra un retiro de efectivo', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        $component = Livewire::test(CashDrawer::class)
            ->set('movementType', 'out')
            ->set('movementAmount', 120)
            ->set('movementReason', 'Pago de mensajeria')
            ->call('saveMovement')
            ->assertHasNoErrors();

        expect($component->get('summary')['expected'])->toBe(380.0);
    });

    it('no deja retirar mas de lo que hay', function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 100);

        Livewire::test(CashDrawer::class)
            ->set('movementType', 'out')
            ->set('movementAmount', 500)
            ->set('movementReason', 'Retiro imposible')
            ->call('saveMovement')
            ->assertHasErrors('movementAmount');
    });

    it('cierra la caja guardando la diferencia', function () {
        $shift = app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        Livewire::test(CashDrawer::class)
            ->set('countedAmount', 480)
            ->call('close')
            ->assertHasNoErrors();

        $closed = $shift->fresh();

        expect($closed->status)->toBe('closed')
            ->and($closed->difference)->toBe(-20.0);
    });
});

// =============================================================
// Ticket y anulacion
// =============================================================

describe('ticket', function () {
    beforeEach(function () {
        $shift = app(CashRegister::class)->open($this->terminalId, $this->branchId, 500);

        $this->sale = app(SaleRegistrar::class)->register(
            shift: $shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 30]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 60]],
        );
    });

    it('muestra el ticket con sus lineas', function () {
        Livewire::test(Show::class, ['saleId' => $this->sale->id])
            ->assertSee($this->sale->folio)
            ->assertSee('Arroz 1kg');
    });

    it('anula la venta y devuelve la mercancia', function () {
        $before = Inventory::where('product_id', $this->product->id)->value('quantity');

        Livewire::test(Show::class, ['saleId' => $this->sale->id])
            ->set('cancelReason', 'El cliente devolvio el producto')
            ->call('cancel')
            ->assertHasNoErrors();

        expect($this->sale->fresh()->status)->toBe('cancelled')
            ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))
            ->toBe($before + 2);
    });

    it('exige un motivo para anular', function () {
        Livewire::test(Show::class, ['saleId' => $this->sale->id])
            ->set('cancelReason', '')
            ->call('cancel')
            ->assertHasErrors('cancelReason');

        expect($this->sale->fresh()->status)->toBe('completed');
    });

    it('no deja anular sin el permiso', function () {
        $this->context['user']->update(['permissions_override' => ['sales.void' => false]]);

        Livewire::test(Show::class, ['saleId' => $this->sale->id])
            ->set('cancelReason', 'Motivo suficiente')
            ->call('cancel')
            ->assertForbidden();
    });
});

// =============================================================
// Promociones en la caja
// =============================================================

describe('promociones en la caja', function () {
    /**
     * Lo que la caja muestra tiene que ser exactamente lo que se va a
     * cobrar: si el cajero anuncia un total y el sistema cobra otro, el
     * problema no lo tiene el sistema, lo tiene quien esta atendiendo.
     */
    beforeEach(function () {
        app(CashRegister::class)->open($this->terminalId, $this->branchId, 0);

        $this->promo = Promotion::create([
            'name' => '2x1 en arroz',
            'type' => 'nxm',
            'applies_to_all' => true,
            'buy_quantity' => 2,
            'get_quantity' => 1,
        ]);
    });

    it('descuenta la promocion en el total del carrito', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id);

        $key = array_key_first($component->get('cart'));

        $component->set("cart.{$key}.quantity", 4);

        // 4 a 30 son 120; el 2x1 regala 2, quedan 60.
        expect($component->instance()->totals['subtotal'])->toBe(120.0)
            ->and($component->instance()->totals['promotion'])->toBe(60.0)
            ->and($component->instance()->totals['total'])->toBe(60.0);
    });

    it('anuncia la promocion en la linea', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id);

        $key = array_key_first($component->get('cart'));

        $component->set("cart.{$key}.quantity", 2)
            ->assertSee('2x1 en arroz')
            ->assertSee('ahorra');
    });

    it('no anuncia nada cuando no se alcanza la promocion', function () {
        Livewire::test(Register::class)
            ->call('addProduct', $this->product->id)
            ->assertDontSee('ahorra');
    });

    it('cobra lo mismo que mostro', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id);

        $key = array_key_first($component->get('cart'));

        $component->set("cart.{$key}.quantity", 4);

        $shown = $component->instance()->totals['total'];

        $component->call('openPayment')
            ->call('checkout')
            ->assertHasNoErrors();

        expect(Sale::latest('created_at')->first()->total)->toBe($shown);
    });

    it('la promocion apagada deja de descontar', function () {
        $this->promo->update(['status' => 'inactive']);

        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id);

        $key = array_key_first($component->get('cart'));
        $component->set("cart.{$key}.quantity", 4);

        expect($component->instance()->totals['total'])->toBe(120.0);
    });

    it('el efectivo se propone ya con la promocion aplicada', function () {
        $component = Livewire::test(Register::class)
            ->call('addProduct', $this->product->id);

        $key = array_key_first($component->get('cart'));

        $component->set("cart.{$key}.quantity", 4)->call('openPayment');

        // Proponer el importe sin descuento haria que el cajero cobre de
        // mas en la venta mas comun, la de efectivo exacto.
        expect(array_values($component->get('payments'))[0])->toBe(60.0);
    });
});
