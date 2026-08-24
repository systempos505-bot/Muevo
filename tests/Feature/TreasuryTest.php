<?php

use App\Models\Account;
use App\Models\AccountMovement;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\CustomerAccount;
use App\Services\ExpenseRegistrar;
use App\Services\InventoryManager;
use App\Services\PurchaseRegistrar;
use App\Services\SaleRegistrar;
use App\Services\Treasury;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->treasury = app(Treasury::class);
    $this->expenses = app(ExpenseRegistrar::class);

    $this->caja = Account::where('name', 'Caja')->first();
    $this->banco = Account::where('name', 'Banco')->first();

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->cardMethod = PaymentMethod::where('code', 'TAR')->first();
    $this->creditMethod = PaymentMethod::where('code', 'CRE')->first();
});

// =============================================================
// Aprovisionamiento
// =============================================================

it('crea las cuentas iniciales ligadas a las formas de pago', function () {
    expect($this->caja)->not->toBeNull()
        ->and($this->banco)->not->toBeNull()
        ->and($this->caja->type)->toBe('cash')
        ->and($this->caja->is_default)->toBeTrue();

    // El efectivo cae en caja, la tarjeta en el banco, el credito en
    // ninguna parte hasta que el cliente pague.
    expect($this->cashMethod->account_id)->toBe($this->caja->id)
        ->and($this->cardMethod->account_id)->toBe($this->banco->id)
        ->and($this->creditMethod->account_id)->toBeNull();

    expect(ExpenseCategory::count())->toBe(7);
});

// =============================================================
// Movimientos
// =============================================================

describe('movimientos', function () {
    it('sube el saldo con una entrada y deja su renglon', function () {
        $movement = $this->treasury->move($this->caja, 'in', 500, 'Fondo inicial');

        expect($this->caja->fresh()->balance)->toBe(500.0)
            ->and($movement->balance)->toBe(500.0)
            ->and($movement->direction)->toBe('in');
    });

    it('arrastra el saldo entre movimientos', function () {
        $this->treasury->move($this->caja, 'in', 1000, 'Deposito');
        $this->treasury->move($this->caja, 'out', 300, 'Retiro');
        $last = $this->treasury->move($this->caja, 'out', 200, 'Otro retiro');

        expect($this->caja->fresh()->balance)->toBe(500.0)
            ->and($last->balance)->toBe(500.0);

        // Cada renglon guarda como quedo la cuenta en ese momento.
        expect(AccountMovement::orderBy('id')->pluck('balance')->all())
            ->toBe([1000.0, 700.0, 500.0]);
    });

    it('rechaza un monto en cero o negativo', function () {
        $this->treasury->move($this->caja, 'in', 0, 'Nada');
    })->throws(RuntimeException::class, 'mayor que cero');

    it('rechaza una direccion invalida', function () {
        $this->treasury->move($this->caja, 'lateral', 100, 'Raro');
    })->throws(RuntimeException::class, 'Direccion de movimiento invalida');
});

// =============================================================
// Traslados
// =============================================================

describe('traslados', function () {
    beforeEach(function () {
        $this->treasury->move($this->caja, 'in', 2000, 'Fondo');
    });

    it('mueve dinero entre cuentas de la misma moneda', function () {
        $transfer = $this->treasury->transfer($this->caja->fresh(), $this->banco, 800);

        expect($this->caja->fresh()->balance)->toBe(1200.0)
            ->and($this->banco->fresh()->balance)->toBe(800.0)
            ->and($transfer->amount_from)->toBe(800.0)
            ->and($transfer->amount_to)->toBe(800.0)
            ->and($transfer->isCrossCurrency())->toBeFalse();
    });

    it('deja los dos movimientos ligados al traslado', function () {
        $transfer = $this->treasury->transfer($this->caja, $this->banco, 500);

        $movements = AccountMovement::where('source', 'transfer')
            ->where('source_id', $transfer->id)
            ->get();

        expect($movements)->toHaveCount(2)
            ->and($movements->where('direction', 'out')->count())->toBe(1)
            ->and($movements->where('direction', 'in')->count())->toBe(1);
    });

    it('convierte cuando las cuentas tienen monedas distintas', function () {
        // La moneda principal vale 1; el euro vale 25 veces mas.
        $eur = Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => 25,
        ]);

        $euros = Account::create([
            'name' => 'Cuenta en euros', 'type' => 'bank', 'currency_id' => $eur->id,
        ]);

        // 500 de moneda local dan 20 euros.
        $transfer = $this->treasury->transfer($this->caja, $euros, 500);

        expect($transfer->amount_from)->toBe(500.0)
            ->and($transfer->amount_to)->toBe(20.0)
            ->and($transfer->exchange_rate)->toBe(0.04)
            ->and($transfer->isCrossCurrency())->toBeTrue()
            ->and($euros->fresh()->balance)->toBe(20.0);
    });

    it('no deja trasladar mas de lo que hay', function () {
        $this->treasury->transfer($this->caja, $this->banco, 5000);
    })->throws(RuntimeException::class, 'No hay tanto saldo');

    it('no deja trasladar a la misma cuenta', function () {
        $this->treasury->transfer($this->caja, $this->caja, 100);
    })->throws(RuntimeException::class, 'dos cuentas distintas');
});

// =============================================================
// Integracion con ventas, compras y abonos
// =============================================================

describe('integracion', function () {
    beforeEach(function () {
        $this->product = Product::create([
            'sku' => 'P-1',
            'name' => 'Producto',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'cost' => 40,
        ]);

        app(InventoryManager::class)->move($this->product, $this->branchId, 100, 'initial');
        $this->shift = app(CashRegister::class)->open($this->terminalId, $this->branchId, 0);
    });

    it('una venta en efectivo entra a la caja', function () {
        app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 300]],
        );

        expect($this->caja->fresh()->balance)->toBe(300.0)
            ->and($this->banco->fresh()->balance)->toBe(0.0);
    });

    it('una venta con tarjeta entra al banco', function () {
        app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300]],
            payments: [['payment_method_id' => $this->cardMethod->id, 'amount' => 300]],
        );

        expect($this->banco->fresh()->balance)->toBe(300.0)
            ->and($this->caja->fresh()->balance)->toBe(0.0);
    });

    it('descuenta el cambio de lo que entra a la caja', function () {
        app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 280]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 500]],
        );

        // Entraron 500 pero salieron 220 de cambio: quedan 280.
        expect($this->caja->fresh()->balance)->toBe(280.0);
    });

    it('una venta a credito no mueve ninguna cuenta', function () {
        $customer = Customer::create([
            'name' => 'Cliente', 'credit_enabled' => true, 'credit_limit' => 1000,
        ]);

        app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300]],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 300]],
            customerId: $customer->id,
        );

        // El dinero todavia no existe: el cliente no ha pagado.
        expect($this->caja->fresh()->balance)->toBe(0.0)
            ->and($this->banco->fresh()->balance)->toBe(0.0);
    });

    it('el abono del cliente si entra a la caja', function () {
        $customer = Customer::create([
            'name' => 'Cliente', 'credit_enabled' => true, 'credit_limit' => 1000,
        ]);

        app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300]],
            payments: [['payment_method_id' => $this->creditMethod->id, 'amount' => 300]],
            customerId: $customer->id,
        );

        app(CustomerAccount::class)->receivePayment(
            customer: $customer->fresh(),
            amount: 200,
            paymentMethodId: $this->cashMethod->id,
        );

        expect($this->caja->fresh()->balance)->toBe(200.0);
    });

    it('una compra de contado sale de la cuenta', function () {
        $this->treasury->move($this->caja, 'in', 5000, 'Fondo');

        app(PurchaseRegistrar::class)->register(
            branchId: $this->branchId,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 50]],
            supplierId: Supplier::create(['name' => 'Proveedor'])->id,
            paymentType: 'cash',
            paymentMethodId: $this->cashMethod->id,
        );

        // 10 x 50 = 500 (el producto es exento en esta empresa).
        expect($this->caja->fresh()->balance)->toBe(4500.0);
    });

    it('el abono a una compra a credito sale de la cuenta', function () {
        $this->treasury->move($this->caja, 'in', 5000, 'Fondo');

        $purchase = app(PurchaseRegistrar::class)->register(
            branchId: $this->branchId,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 50]],
            supplierId: Supplier::create(['name' => 'Proveedor'])->id,
            paymentType: 'credit',
        );

        // A credito no salio nada todavia.
        expect($this->caja->fresh()->balance)->toBe(5000.0);

        app(PurchaseRegistrar::class)->pay($purchase, 300, $this->cashMethod->id);

        expect($this->caja->fresh()->balance)->toBe(4700.0);
    });
});

// =============================================================
// Gastos
// =============================================================

describe('gastos', function () {
    beforeEach(function () {
        $this->treasury->move($this->caja, 'in', 3000, 'Fondo');
        $this->categoria = ExpenseCategory::where('name', 'Renta')->first();
    });

    it('registra el gasto y baja el saldo de la cuenta', function () {
        $expense = $this->expenses->register(
            total: 1200,
            description: 'Renta del local',
            categoryId: $this->categoria->id,
            accountId: $this->caja->id,
        );

        expect($expense->folio)->toStartWith('G-')
            ->and($expense->total)->toBe(1200.0)
            ->and($this->caja->fresh()->balance)->toBe(1800.0);

        $movement = AccountMovement::where('source', 'expense')->sole();
        expect($movement->direction)->toBe('out')
            ->and($movement->amount)->toBe(1200.0);
    });

    it('separa el impuesto del subtotal', function () {
        $expense = $this->expenses->register(
            total: 1150,
            description: 'Servicio con impuesto',
            tax: 150,
            accountId: $this->caja->id,
        );

        expect($expense->subtotal)->toBe(1000.0)
            ->and($expense->tax)->toBe(150.0)
            ->and($expense->total)->toBe(1150.0);
    });

    it('no deja gastar mas de lo que hay en la cuenta', function () {
        $this->expenses->register(
            total: 9000,
            description: 'Gasto imposible',
            accountId: $this->caja->id,
        );
    })->throws(RuntimeException::class, 'No hay tanto saldo');

    it('rechaza un impuesto mayor que el total', function () {
        $this->expenses->register(
            total: 100,
            description: 'Raro',
            tax: 200,
            accountId: $this->caja->id,
        );
    })->throws(RuntimeException::class, 'no puede ser mayor que el total');

    it('rechaza un total en cero', function () {
        $this->expenses->register(total: 0, description: 'Nada');
    })->throws(RuntimeException::class, 'mayor que cero');

    it('permite un gasto sin cuenta, sin mover dinero', function () {
        // Un negocio puede no querer llevar tesoreria y solo anotar.
        $expense = $this->expenses->register(total: 500, description: 'Gasto sin cuenta');

        expect($expense->account_id)->toBeNull()
            ->and($this->caja->fresh()->balance)->toBe(3000.0)
            ->and(AccountMovement::where('source', 'expense')->count())->toBe(0);
    });

    it('anula el gasto y devuelve el dinero', function () {
        $expense = $this->expenses->register(
            total: 1200,
            description: 'Renta',
            accountId: $this->caja->id,
        );

        $this->expenses->cancel($expense, 'Se pago dos veces por error');

        expect($expense->fresh()->status)->toBe('cancelled')
            ->and($this->caja->fresh()->balance)->toBe(3000.0);
    });

    it('no deja anular dos veces', function () {
        $expense = $this->expenses->register(
            total: 100, description: 'Gasto', accountId: $this->caja->id,
        );

        $this->expenses->cancel($expense, 'Primera');
        $this->expenses->cancel($expense->fresh(), 'Segunda');
    })->throws(RuntimeException::class, 'ya estaba anulado');

    it('repite un gasto recurrente con la fecha de hoy', function () {
        $original = $this->expenses->register(
            total: 1200,
            description: 'Renta del local',
            categoryId: $this->categoria->id,
            accountId: $this->caja->id,
            expenseDate: '2026-01-05',
            isRecurring: true,
        );

        $copy = $this->expenses->repeat($original);

        expect($copy->id)->not->toBe($original->id)
            ->and($copy->total)->toBe(1200.0)
            ->and($copy->description)->toBe('Renta del local')
            ->and($copy->expense_date->toDateString())->toBe(now()->toDateString())
            ->and(Expense::count())->toBe(2);
    });

    it('numera los gastos sin repetir folio', function () {
        $folios = [];

        for ($i = 0; $i < 3; $i++) {
            $folios[] = $this->expenses->register(
                total: 10, description: 'Gasto', accountId: $this->caja->id,
            )->folio;
        }

        expect($folios)->toBe(['G-000001', 'G-000002', 'G-000003']);
    });
});

// =============================================================
// Flujo de dinero
// =============================================================

describe('flujo de dinero', function () {
    it('resume entradas, salidas y neto', function () {
        $this->treasury->move($this->caja, 'in', 1000, 'Venta', 'sale');
        $this->treasury->move($this->caja, 'in', 500, 'Abono', 'customer_payment');
        $this->treasury->move($this->caja, 'out', 300, 'Gasto', 'expense');

        $flow = $this->treasury->cashFlow();

        expect($flow['in'])->toBe(1500.0)
            ->and($flow['out'])->toBe(300.0)
            ->and($flow['net'])->toBe(1200.0)
            ->and($flow['by_source']['sale'])->toBe(1000.0)
            ->and($flow['by_source']['expense'])->toBe(-300.0);
    });

    it('deja los traslados fuera del desglose por origen', function () {
        $this->treasury->move($this->caja, 'in', 1000, 'Venta', 'sale');
        $this->treasury->transfer($this->caja, $this->banco, 400);

        $flow = $this->treasury->cashFlow();

        // El dinero solo cambio de bolsillo: no es ingreso ni gasto.
        expect($flow['by_source'])->not->toHaveKey('transfer')
            ->and($flow['by_source']['sale'])->toBe(1000.0);
    });

    it('suma los saldos de todas las cuentas en moneda principal', function () {
        $this->treasury->move($this->caja, 'in', 1000, 'Fondo');
        $this->treasury->move($this->banco, 'in', 2500, 'Deposito');

        expect($this->treasury->totalBalance())->toBe(3500.0);
    });

    it('convierte a moneda principal los saldos en otra moneda', function () {
        $eur = Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => 25,
        ]);

        $euros = Account::create([
            'name' => 'Euros', 'type' => 'bank', 'currency_id' => $eur->id,
        ]);

        $this->treasury->move($this->caja, 'in', 1000, 'Fondo');
        $this->treasury->move($euros, 'in', 100, 'Deposito');

        // 100 euros a 25 son 2500 de moneda local.
        expect($this->treasury->totalBalance())->toBe(3500.0);
    });
});
