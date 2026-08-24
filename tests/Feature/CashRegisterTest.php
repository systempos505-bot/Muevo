<?php

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->cash = app(CashRegister::class);
    $this->registrar = app(SaleRegistrar::class);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->cardMethod = PaymentMethod::where('code', 'TAR')->first();

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Producto',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 40,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 100, 'initial');
});

describe('apertura', function () {
    it('abre un turno con su fondo', function () {
        $shift = $this->cash->open($this->terminalId, $this->branchId, 500);

        expect($shift->status)->toBe('open')
            ->and($shift->opening_amount)->toBe(500.0)
            ->and($shift->folio)->toStartWith('T-')
            ->and($shift->user_id)->toBe($this->context['user']->id);
    });

    it('no permite dos turnos abiertos en la misma caja', function () {
        $this->cash->open($this->terminalId, $this->branchId, 500);

        // Dos cajeros cuadrando contra el mismo efectivo no podrian saber
        // de quien es la diferencia.
        $this->cash->open($this->terminalId, $this->branchId, 300);
    })->throws(RuntimeException::class, 'ya tiene un turno abierto');

    it('permite abrir de nuevo despues de cerrar', function () {
        $first = $this->cash->open($this->terminalId, $this->branchId, 500);
        $this->cash->close($first, 500);

        $second = $this->cash->open($this->terminalId, $this->branchId, 300);

        expect($second->status)->toBe('open')
            ->and(Shift::count())->toBe(2);
    });

    it('rechaza un fondo negativo', function () {
        $this->cash->open($this->terminalId, $this->branchId, -100);
    })->throws(RuntimeException::class, 'no puede ser negativo');
});

describe('arqueo', function () {
    beforeEach(function () {
        $this->shift = $this->cash->open($this->terminalId, $this->branchId, 500);
    });

    it('suma al cajon solo lo cobrado en efectivo', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 100]],
        );

        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 200]],
            payments: [['payment_method_id' => $this->cardMethod->id, 'amount' => 200]],
        );

        // La tarjeta no entra al cajon: 500 + 100 = 600.
        expect($this->shift->expectedCash())->toBe(600.0)
            ->and($this->shift->salesTotal())->toBe(300.0)
            ->and($this->shift->salesCount())->toBe(2);
    });

    it('resta el cambio entregado', function () {
        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 80]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 100]],
        );

        // Entraron 100 pero salieron 20 de cambio: 500 + 100 - 20 = 580.
        expect($this->shift->expectedCash())->toBe(580.0);
    });

    it('cuenta las entradas y salidas de efectivo', function () {
        $this->cash->move($this->shift, 'in', 200, 'Fondo adicional');
        $this->cash->move($this->shift, 'out', 150, 'Pago a mensajeria');

        expect($this->shift->expectedCash())->toBe(550.0)
            ->and($this->shift->cashIn())->toBe(200.0)
            ->and($this->shift->cashOut())->toBe(150.0);
    });

    it('no deja retirar mas de lo que hay en caja', function () {
        $this->cash->move($this->shift, 'out', 600, 'Retiro imposible');
    })->throws(RuntimeException::class, 'No hay tanto efectivo');

    it('rechaza un movimiento en cero o negativo', function () {
        $this->cash->move($this->shift, 'in', 0, 'Nada');
    })->throws(RuntimeException::class, 'mayor que cero');
});

describe('cierre', function () {
    beforeEach(function () {
        $this->shift = $this->cash->open($this->terminalId, $this->branchId, 500);

        $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 100]],
        );
    });

    it('cierra cuadrado cuando lo contado coincide', function () {
        $closed = $this->cash->close($this->shift, 600);

        expect($closed->status)->toBe('closed')
            ->and($closed->expected_amount)->toBe(600.0)
            ->and($closed->counted_amount)->toBe(600.0)
            ->and($closed->difference)->toBe(0.0)
            ->and($closed->closed_at)->not->toBeNull();
    });

    it('registra el faltante sin corregirlo', function () {
        // Si falta dinero el sistema tiene que decirlo, no taparlo.
        $closed = $this->cash->close($this->shift, 570);

        expect($closed->difference)->toBe(-30.0);
    });

    it('registra el sobrante', function () {
        $closed = $this->cash->close($this->shift, 615);

        expect($closed->difference)->toBe(15.0);
    });

    it('no deja cerrar dos veces', function () {
        $this->cash->close($this->shift, 600);
        $this->cash->close($this->shift->fresh(), 600);
    })->throws(RuntimeException::class, 'ya esta cerrado');

    it('no deja mover efectivo en un turno cerrado', function () {
        $this->cash->close($this->shift, 600);
        $this->cash->move($this->shift->fresh(), 'in', 50, 'Tarde');
    })->throws(RuntimeException::class, 'turno cerrado');
});
