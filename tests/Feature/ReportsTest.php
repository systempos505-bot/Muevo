<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\ExpenseRegistrar;
use App\Services\InventoryManager;
use App\Services\PurchaseRegistrar;
use App\Services\Reports;
use App\Services\SaleRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->reports = app(Reports::class);
    $this->registrar = app(SaleRegistrar::class);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->cardMethod = PaymentMethod::where('code', 'TAR')->first();

    $this->category = Category::create(['name' => 'Abarrotes']);

    // Costo 100, se vende a 150: cada unidad deja 50 de utilidad.
    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto A',
        'category_id' => $this->category->id,
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 100,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 200, 'initial');
    $this->shift = app(CashRegister::class)->open($this->terminalId, $this->branchId, 0);

    $this->today = now()->toDateString();
});

function sell(float $qty, float $price, ?string $method = null): Sale
{
    return test()->registrar->register(
        shift: test()->shift,
        lines: [['product_id' => test()->product->id, 'quantity' => $qty, 'unit_price' => $price]],
        payments: [[
            'payment_method_id' => $method ?? test()->cashMethod->id,
            'amount' => $qty * $price,
        ]],
    );
}

// =============================================================
// Ventas
// =============================================================

describe('resumen de ventas', function () {
    it('suma total, costo y utilidad', function () {
        sell(2, 150);
        sell(1, 150);

        $summary = $this->reports->salesSummary($this->today, $this->today);

        expect($summary['sales'])->toBe(2)
            ->and($summary['total'])->toBe(450.0)
            // 3 unidades a costo 100 = 300 de costo, 150 de utilidad.
            ->and($summary['cost'])->toBe(300.0)
            ->and($summary['profit'])->toBe(150.0)
            ->and($summary['average'])->toBe(225.0);
    });

    it('deja fuera las ventas anuladas', function () {
        $sale = sell(2, 150);
        sell(1, 150);

        $sale->update(['status' => 'cancelled']);

        $summary = $this->reports->salesSummary($this->today, $this->today);

        expect($summary['sales'])->toBe(1)
            ->and($summary['total'])->toBe(150.0);
    });

    it('devuelve ceros cuando no hubo ventas', function () {
        $summary = $this->reports->salesSummary('2020-01-01', '2020-01-31');

        expect($summary['sales'])->toBe(0)
            ->and($summary['total'])->toBe(0.0)
            ->and($summary['average'])->toBe(0.0);
    });

    it('filtra por cajero', function () {
        sell(2, 150);

        $mine = $this->reports->salesSummary(
            $this->today, $this->today, userId: $this->context['user']->id,
        );
        $other = $this->reports->salesSummary(
            $this->today, $this->today, userId: '01a00000-0000-7000-8000-000000000000',
        );

        expect($mine['sales'])->toBe(1)
            ->and($other['sales'])->toBe(0);
    });
});

describe('ventas por dia', function () {
    it('rellena con cero los dias sin ventas', function () {
        sell(1, 150);

        $days = $this->reports->salesByDay(
            now()->subDays(2)->toDateString(),
            $this->today,
        );

        // Un hueco en la grafica se leeria como "no hay dato".
        expect($days)->toHaveCount(3)
            ->and($days[0]['total'])->toBe(0.0)
            ->and($days[1]['total'])->toBe(0.0)
            ->and($days[2]['total'])->toBe(150.0)
            ->and($days[2]['sales'])->toBe(1);
    });
});

describe('desgloses de venta', function () {
    it('ordena los productos mas vendidos por importe', function () {
        $otro = Product::create([
            'sku' => 'P-2',
            'name' => 'Producto B',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'cost' => 20,
        ]);
        app(InventoryManager::class)->move($otro, $this->branchId, 100, 'initial');

        sell(1, 150);
        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $otro->id, 'quantity' => 10, 'unit_price' => 50]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 500]],
        );

        $top = $this->reports->topProducts($this->today, $this->today);

        expect($top[0]['name'])->toBe('Producto B')
            ->and($top[0]['total'])->toBe(500.0)
            ->and($top[0]['quantity'])->toBe(10.0)
            // 10 unidades: se vendieron a 50 y costaron 20.
            ->and($top[0]['profit'])->toBe(300.0)
            ->and($top[1]['name'])->toBe('Producto A');
    });

    it('separa por forma de pago', function () {
        sell(1, 150, $this->cashMethod->id);
        sell(2, 150, $this->cardMethod->id);

        $methods = collect($this->reports->salesByPaymentMethod($this->today, $this->today))
            ->keyBy('method');

        expect($methods['Efectivo']['total'])->toBe(150.0)
            ->and($methods['Tarjeta']['total'])->toBe(300.0);
    });

    it('agrupa por cajero', function () {
        sell(2, 150);

        $byUser = $this->reports->salesByUser($this->today, $this->today);

        expect($byUser)->toHaveCount(1)
            ->and($byUser[0]['name'])->toBe('Dueno')
            ->and($byUser[0]['total'])->toBe(300.0);
    });

    it('agrupa por categoria', function () {
        sell(2, 150);

        $byCategory = $this->reports->salesByCategory($this->today, $this->today);

        expect($byCategory[0]['name'])->toBe('Abarrotes')
            ->and($byCategory[0]['total'])->toBe(300.0);
    });

    it('agrupa como sin categoria lo que no tiene', function () {
        $suelto = Product::create([
            'sku' => 'P-3',
            'name' => 'Sin categoria',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);
        app(InventoryManager::class)->move($suelto, $this->branchId, 10, 'initial');

        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $suelto->id, 'quantity' => 1, 'unit_price' => 80]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 80]],
        );

        $byCategory = collect($this->reports->salesByCategory($this->today, $this->today))
            ->keyBy('name');

        expect($byCategory['Sin categoria']['total'])->toBe(80.0);
    });
});

// =============================================================
// Utilidad real
// =============================================================

describe('utilidad', function () {
    it('resta los gastos de la utilidad bruta', function () {
        sell(4, 150);   // 600 vendidos, 400 de costo, 200 de utilidad bruta

        app(ExpenseRegistrar::class)->register(
            total: 80,
            description: 'Renta',
            categoryId: ExpenseCategory::where('name', 'Renta')->value('id'),
        );

        $pl = $this->reports->profitAndLoss($this->today, $this->today);

        expect($pl['revenue'])->toBe(600.0)
            ->and($pl['cost'])->toBe(400.0)
            ->and($pl['gross_profit'])->toBe(200.0)
            ->and($pl['expenses'])->toBe(80.0)
            ->and($pl['net_profit'])->toBe(120.0)
            ->and($pl['margin'])->toBe(20.0);
    });

    it('puede dar perdida cuando los gastos superan la utilidad', function () {
        sell(1, 150);   // 50 de utilidad bruta

        app(ExpenseRegistrar::class)->register(total: 300, description: 'Reparacion');

        $pl = $this->reports->profitAndLoss($this->today, $this->today);

        expect($pl['net_profit'])->toBe(-250.0);
    });

    it('no divide entre cero sin ventas', function () {
        $pl = $this->reports->profitAndLoss($this->today, $this->today);

        expect($pl['margin'])->toBe(0.0)
            ->and($pl['net_profit'])->toBe(0.0);
    });
});

// =============================================================
// Compras, gastos e inventario
// =============================================================

describe('otros reportes', function () {
    it('resume las compras y lo que queda por pagar', function () {
        app(PurchaseRegistrar::class)->register(
            branchId: $this->branchId,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 90]],
            supplierId: Supplier::create(['name' => 'Proveedor'])->id,
            paymentType: 'credit',
        );

        $summary = $this->reports->purchasesSummary($this->today, $this->today);

        expect($summary['purchases'])->toBe(1)
            ->and($summary['total'])->toBe(900.0)
            ->and($summary['pending'])->toBe(900.0);
    });

    it('desglosa los gastos por categoria', function () {
        $registrar = app(ExpenseRegistrar::class);
        $renta = ExpenseCategory::where('name', 'Renta')->value('id');
        $servicios = ExpenseCategory::where('name', 'Servicios')->value('id');

        $registrar->register(total: 1000, description: 'Renta', categoryId: $renta);
        $registrar->register(total: 300, description: 'Luz', categoryId: $servicios);
        $registrar->register(total: 200, description: 'Agua', categoryId: $servicios);

        $summary = $this->reports->expensesSummary($this->today, $this->today);

        expect($summary['total'])->toBe(1500.0)
            ->and($summary['by_category'][0]['name'])->toBe('Renta')
            ->and($summary['by_category'][1]['total'])->toBe(500.0);
    });

    it('valoriza el inventario a costo', function () {
        $value = $this->reports->inventoryValue();

        // 200 unidades a costo 100.
        expect($value['products'])->toBe(1)
            ->and($value['units'])->toBe(200.0)
            ->and($value['value'])->toBe(20000.0);
    });

    it('suma lo que deben los clientes y lo que se debe', function () {
        Customer::create(['name' => 'Cliente', 'balance' => 500]);
        Supplier::create(['name' => 'Proveedor', 'balance' => 800]);

        $balances = $this->reports->balances();

        expect($balances['receivable'])->toBe(500.0)
            ->and($balances['payable'])->toBe(800.0);
    });

    it('detecta el producto estancado que no se ha vendido', function () {
        $estancado = Product::create([
            'sku' => 'P-9',
            'name' => 'Producto olvidado',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'cost' => 30,
        ]);
        app(InventoryManager::class)->move($estancado, $this->branchId, 40, 'initial');

        sell(1, 150);

        $dead = $this->reports->deadStock($this->today, $this->today);

        expect($dead)->toHaveCount(1)
            ->and($dead[0]['name'])->toBe('Producto olvidado')
            ->and($dead[0]['stock'])->toBe(40.0)
            ->and($dead[0]['value'])->toBe(1200.0);
    });

    it('no considera estancado lo que no tiene existencia', function () {
        Product::create([
            'sku' => 'P-8',
            'name' => 'Agotado',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        sell(1, 150);

        expect($this->reports->deadStock($this->today, $this->today))->toBe([]);
    });
});
