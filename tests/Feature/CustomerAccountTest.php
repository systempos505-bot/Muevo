<?php

use App\Livewire\Partners\Customers as CustomersScreen;
use App\Livewire\Partners\CustomerShow;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerType;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\CustomerAccount;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->account = app(CustomerAccount::class);
    $this->registrar = app(SaleRegistrar::class);
    $this->cash = app(CashRegister::class);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->creditMethod = PaymentMethod::where('code', 'CRE')->first();

    $this->customer = Customer::create([
        'name' => 'Maria Fernandez',
        'credit_enabled' => true,
        'credit_limit' => 2000,
        'credit_days' => 15,
    ]);

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 40,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 100, 'initial');
    $this->shift = $this->cash->open($this->terminalId, $this->branchId, 500);
});

function creditSale(float $amount): Sale
{
    return test()->registrar->register(
        shift: test()->shift,
        lines: [['product_id' => test()->product->id, 'quantity' => 1, 'unit_price' => $amount]],
        payments: [['payment_method_id' => test()->creditMethod->id, 'amount' => $amount]],
        customerId: test()->customer->id,
    );
}

// =============================================================
// Abonos
// =============================================================

describe('abonos', function () {
    it('baja el saldo del cliente', function () {
        creditSale(500);

        $this->account->receivePayment(
            customer: $this->customer->fresh(),
            amount: 200,
            paymentMethodId: $this->cashMethod->id,
        );

        expect($this->customer->fresh()->balance)->toBe(300.0)
            ->and(CustomerPayment::count())->toBe(1);
    });

    it('no deja abonar mas de lo que debe', function () {
        creditSale(500);

        $this->account->receivePayment($this->customer->fresh(), 900, $this->cashMethod->id);
    })->throws(RuntimeException::class, 'supera lo que debe');

    it('rechaza un abono en cero', function () {
        creditSale(500);

        $this->account->receivePayment($this->customer->fresh(), 0, $this->cashMethod->id);
    })->throws(RuntimeException::class, 'mayor que cero');

    it('deja la cuenta en cero al abonar todo', function () {
        creditSale(500);

        $this->account->receivePayment($this->customer->fresh(), 500, $this->cashMethod->id);

        expect($this->customer->fresh()->balance)->toBe(0.0);
    });

    it('cuenta en el arqueo cuando se recibe en efectivo', function () {
        creditSale(500);

        $expectedBefore = $this->shift->expectedCash();

        $this->account->receivePayment(
            customer: $this->customer->fresh(),
            amount: 200,
            paymentMethodId: $this->cashMethod->id,
            shift: $this->shift,
        );

        // El dinero esta fisicamente en el cajon, asi que tiene que
        // aparecer al cuadrar.
        expect($this->shift->expectedCash())->toBe($expectedBefore + 200);
    });

    it('no cuenta en el arqueo si se recibio por transferencia', function () {
        creditSale(500);

        $expectedBefore = $this->shift->expectedCash();

        $this->account->receivePayment(
            customer: $this->customer->fresh(),
            amount: 200,
            paymentMethodId: PaymentMethod::where('code', 'TRA')->value('id'),
            shift: $this->shift,
        );

        expect($this->shift->expectedCash())->toBe($expectedBefore);
    });
});

// =============================================================
// Estado de cuenta
// =============================================================

describe('estado de cuenta', function () {
    it('arma cargos y abonos con el saldo corrido', function () {
        creditSale(500);
        creditSale(300);
        $this->account->receivePayment($this->customer->fresh(), 200, $this->cashMethod->id);

        $statement = $this->account->statement($this->customer->fresh());

        expect($statement)->toHaveCount(3);

        // Viene del mas reciente al mas antiguo: abono, venta, venta.
        expect($statement[0]['type'])->toBe('payment')
            ->and($statement[0]['payment'])->toBe(200.0)
            ->and($statement[0]['balance'])->toBe(600.0);

        expect($statement[1]['charge'])->toBe(300.0)
            ->and($statement[1]['balance'])->toBe(800.0);

        expect($statement[2]['charge'])->toBe(500.0)
            ->and($statement[2]['balance'])->toBe(500.0);

        // El ultimo saldo del estado coincide con el del cliente.
        expect($statement[0]['balance'])->toBe($this->customer->fresh()->balance);
    });

    it('esta vacio para un cliente sin credito usado', function () {
        expect($this->account->statement($this->customer))->toBe([]);
    });

    it('solo cuenta la parte a credito de una venta mixta', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]],
            payments: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 200],
                ['payment_method_id' => $this->creditMethod->id, 'amount' => 300],
            ],
            customerId: $this->customer->id,
        );

        $statement = $this->account->statement($this->customer->fresh());

        // La venta fue de 500 pero solo 300 quedaron a deber.
        expect($statement[0]['charge'])->toBe(300.0);
    });
});

// =============================================================
// Credito disponible
// =============================================================

describe('credito disponible', function () {
    it('resta lo que ya debe del limite', function () {
        creditSale(500);

        expect($this->account->availableCredit($this->customer->fresh()))->toBe(1500.0);
    });

    it('devuelve null cuando no hay limite fijado', function () {
        $this->customer->update(['credit_limit' => 0]);

        expect($this->account->availableCredit($this->customer->fresh()))->toBeNull();
    });

    it('devuelve cero para un cliente sin credito', function () {
        $this->customer->update(['credit_enabled' => false]);

        expect($this->account->availableCredit($this->customer->fresh()))->toBe(0.0);
    });
});

// =============================================================
// Pantallas
// =============================================================

describe('pantalla de clientes', function () {
    it('crea un cliente con credito', function () {
        Livewire::test(CustomersScreen::class)
            ->call('create')
            ->set('name', 'Tienda Don Beto')
            ->set('creditEnabled', true)
            ->set('creditLimit', 5000)
            ->set('creditDays', 30)
            ->call('save')
            ->assertHasNoErrors();

        $created = Customer::where('name', 'Tienda Don Beto')->first();

        expect($created->credit_enabled)->toBeTrue()
            ->and($created->credit_limit)->toBe(5000.0);
    });

    it('no guarda limite ni plazo si no se le vende a credito', function () {
        Livewire::test(CustomersScreen::class)
            ->call('create')
            ->set('name', 'Solo contado')
            ->set('creditEnabled', false)
            ->set('creditLimit', 5000)
            ->set('creditDays', 30)
            ->call('save');

        $created = Customer::where('name', 'Solo contado')->first();

        expect($created->credit_limit)->toBe(0.0)
            ->and($created->credit_days)->toBe(0);
    });

    it('no deja bajar el limite por debajo del saldo', function () {
        creditSale(1500);

        Livewire::test(CustomersScreen::class)
            ->call('edit', $this->customer->id)
            ->set('creditLimit', 500)
            ->call('save')
            ->assertHasErrors('creditLimit');

        expect($this->customer->fresh()->credit_limit)->toBe(2000.0);
    });

    it('no deja desactivar a un cliente con saldo', function () {
        creditSale(500);

        Livewire::test(CustomersScreen::class)->call('toggleStatus', $this->customer->id);

        expect($this->customer->fresh()->status)->toBe('active');
    });

    it('suma el total por cobrar', function () {
        creditSale(500);
        Customer::create(['name' => 'Otro', 'balance' => 300]);

        expect(Livewire::test(CustomersScreen::class)->get('totalReceivable'))->toBe(800.0);
    });

    it('filtra por clientes con saldo', function () {
        creditSale(500);
        Customer::create(['name' => 'Sin deuda']);

        Livewire::test(CustomersScreen::class)
            ->set('filter', 'debt')
            ->assertSee('Maria Fernandez')
            ->assertDontSee('Sin deuda');
    });
});

describe('ficha del cliente', function () {
    it('muestra el saldo y el estado de cuenta', function () {
        $sale = creditSale(500);

        Livewire::test(CustomerShow::class, ['customerId' => $this->customer->id])
            ->assertSee('Maria Fernandez')
            ->assertSee($sale->folio)
            ->assertSet('paymentAmount', 500.0);
    });

    it('registra un abono desde la ficha', function () {
        creditSale(500);

        Livewire::test(CustomerShow::class, ['customerId' => $this->customer->id])
            ->set('paymentAmount', 200)
            ->set('paymentMethodId', $this->cashMethod->id)
            ->call('pay')
            ->assertHasNoErrors();

        expect($this->customer->fresh()->balance)->toBe(300.0);
    });

    it('muestra el error cuando el abono supera la deuda', function () {
        creditSale(500);

        Livewire::test(CustomerShow::class, ['customerId' => $this->customer->id])
            ->set('paymentAmount', 900)
            ->set('paymentMethodId', $this->cashMethod->id)
            ->call('pay')
            ->assertHasErrors('paymentAmount');
    });

    it('no ofrece abonar a quien no debe nada', function () {
        Livewire::test(CustomerShow::class, ['customerId' => $this->customer->id])
            ->call('openPayment')
            ->assertSet('showPayment', false)
            ->assertDispatched('notify');
    });

    it('no deja abonar sin el permiso', function () {
        creditSale(500);
        $this->context['user']->update(['permissions_override' => ['customers.edit' => false]]);

        Livewire::test(CustomerShow::class, ['customerId' => $this->customer->id])
            ->set('paymentAmount', 100)
            ->call('pay')
            ->assertForbidden();
    });
});

// =============================================================
// Lista de precios del cliente
// =============================================================

it('hereda la lista de precios de su tipo de cliente', function () {
    $mayoreo = PriceList::where('name', 'Mayoreo')->first();
    $tipo = CustomerType::create(['name' => 'Mayorista', 'price_list_id' => $mayoreo->id]);

    $customer = Customer::create(['name' => 'Tienda', 'customer_type_id' => $tipo->id]);

    expect($customer->effectivePriceListId())->toBe($mayoreo->id);
});

it('la lista propia del cliente manda sobre la de su tipo', function () {
    $publico = PriceList::where('is_default', true)->first();
    $mayoreo = PriceList::where('name', 'Mayoreo')->first();
    $tipo = CustomerType::create(['name' => 'Mayorista', 'price_list_id' => $mayoreo->id]);

    $customer = Customer::create([
        'name' => 'Tienda',
        'customer_type_id' => $tipo->id,
        'price_list_id' => $publico->id,
    ]);

    expect($customer->effectivePriceListId())->toBe($publico->id);
});
