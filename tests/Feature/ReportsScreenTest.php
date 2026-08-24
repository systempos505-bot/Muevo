<?php

use App\Livewire\Reports\Index;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\ExpenseRegistrar;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto A',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 100,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 50, 'initial');

    $this->shift = app(CashRegister::class)->open($this->terminalId, $this->branchId, 0);
});

function registerSale(float $qty = 2, float $price = 150): void
{
    app(SaleRegistrar::class)->register(
        shift: test()->shift,
        lines: [['product_id' => test()->product->id, 'quantity' => $qty, 'unit_price' => $price]],
        payments: [[
            'payment_method_id' => PaymentMethod::where('code', 'EFE')->value('id'),
            'amount' => $qty * $price,
        ]],
    );
}

describe('acceso', function () {
    it('niega la pantalla a quien no tiene el permiso', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'reports.view' => false],
        ]);

        $this->get(route('reports'))->assertForbidden();
    });

    it('abre el mes en curso por defecto', function () {
        Livewire::test(Index::class)
            ->assertSet('tab', 'resumen')
            ->assertSet('from', now()->startOfMonth()->toDateString())
            ->assertSet('to', now()->toDateString());
    });

    it('cae en el resumen si la pestana no existe', function () {
        Livewire::withUrlParams(['tab' => 'inventado'])
            ->test(Index::class)
            ->assertSet('tab', 'resumen');
    });
});

describe('periodo', function () {
    it('los atajos mueven las dos fechas', function () {
        Livewire::test(Index::class)
            ->call('preset', 'ayer')
            ->assertSet('from', now()->subDay()->toDateString())
            ->assertSet('to', now()->subDay()->toDateString())
            ->call('preset', 'semana')
            ->assertSet('from', now()->subDays(6)->toDateString())
            ->assertSet('to', now()->toDateString());
    });

    it('el mes pasado va de su primer a su ultimo dia', function () {
        Livewire::test(Index::class)
            ->call('preset', 'mes_pasado')
            ->assertSet('from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->assertSet('to', now()->subMonthNoOverflow()->endOfMonth()->toDateString());
    });

    it('endereza un rango escrito al reves', function () {
        registerSale();

        // Escribir la fecha final antes que la inicial es un desliz comun;
        // devolver cero se leeria como que el negocio no vendio nada.
        $component = Livewire::test(Index::class)
            ->set('from', now()->toDateString())
            ->set('to', now()->subDays(3)->toDateString());

        expect($component->instance()->sales['total'])->toBe(300.0);
    });

    it('el periodo sobrevive al cambio de pestana', function () {
        Livewire::test(Index::class)
            ->call('preset', 'ayer')
            ->call('selectTab', 'productos')
            ->assertSet('tab', 'productos')
            ->assertSet('from', now()->subDay()->toDateString());
    });

    it('ignora una pestana que no existe', function () {
        Livewire::test(Index::class)
            ->call('selectTab', 'contabilidad')
            ->assertSet('tab', 'resumen');
    });
});

describe('cifras en pantalla', function () {
    it('muestra lo vendido y la utilidad neta', function () {
        registerSale(4, 150);   // 600 vendidos, 400 de costo
        app(ExpenseRegistrar::class)->register(
            total: 50,
            description: 'Renta',
            categoryId: ExpenseCategory::where('name', 'Renta')->value('id'),
        );

        $component = Livewire::test(Index::class);

        expect($component->instance()->sales['total'])->toBe(600.0)
            ->and($component->instance()->profit['net_profit'])->toBe(150.0);

        $component->assertSee('600.00')->assertSee('150.00');
    });

    it('lista los productos mas vendidos', function () {
        registerSale();

        Livewire::test(Index::class)
            ->call('selectTab', 'productos')
            ->assertSee('Producto A')
            ->assertSee('300.00');
    });

    it('marca como estancado lo que tiene existencia y no se vendio', function () {
        $olvidado = Product::create([
            'sku' => 'P-2',
            'name' => 'Producto olvidado',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'cost' => 20,
        ]);

        app(InventoryManager::class)->move($olvidado, $this->branchId, 10, 'initial');

        registerSale();

        Livewire::test(Index::class)
            ->call('selectTab', 'inventario')
            ->assertSee('Producto olvidado')
            ->assertDontSee('Producto A');
    });

    it('escala la grafica contra el dia mas alto', function () {
        registerSale();

        $chart = Livewire::test(Index::class)->instance()->chart;
        $today = collect($chart)->firstWhere('date', now()->toDateString());

        expect($today['height'])->toBe(100.0)
            // Un dia sin ventas se queda en cero: una barra minima ahi se
            // leeria como que si hubo algo.
            ->and(collect($chart)->where('total', 0.0)->pluck('height')->unique()->all())
            ->toBe([0.0]);
    });

    it('no divide entre cero cuando no hubo ninguna venta', function () {
        $chart = Livewire::test(Index::class)
            ->set('from', '2020-01-01')
            ->set('to', '2020-01-03')
            ->instance()->chart;

        expect($chart)->toHaveCount(3)
            ->and(collect($chart)->pluck('height')->unique()->all())->toBe([0.0]);
    });
});

describe('exportacion', function () {
    it('baja el resumen como csv', function () {
        registerSale();

        $response = Livewire::test(Index::class)->call('export');

        $response->assertFileDownloaded();

        $csv = downloadedContent($response);

        expect($csv)->toContain('Concepto')
            ->toContain('Utilidad neta')
            ->toContain('300');
    });

    it('exporta la pestana que se esta viendo', function () {
        registerSale();

        $response = Livewire::test(Index::class)
            ->call('selectTab', 'productos')
            ->call('export');

        expect(downloadedContent($response))
            ->toContain('Producto')
            ->toContain('Producto A');
    });

    it('nombra el archivo con el periodo', function () {
        Livewire::test(Index::class)
            ->call('preset', 'ayer')
            ->call('export')
            ->assertFileDownloaded('reporte-resumen-'.now()->subDay()->toDateString().'-a-'.now()->subDay()->toDateString().'.csv');
    });
});
