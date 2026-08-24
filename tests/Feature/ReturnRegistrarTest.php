<?php

use App\Models\Account;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\ReturnRegistrar;
use App\Services\SaleRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;

    $this->registrar = app(SaleRegistrar::class);
    $this->returns = app(ReturnRegistrar::class);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();
    $this->creditMethod = PaymentMethod::where('type', 'credit')->first();

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Camisa',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 100,
    ]);

    app(InventoryManager::class)->move($this->product, $this->branchId, 50, 'initial');

    $this->shift = app(CashRegister::class)->open(
        $this->context['setup']['terminal']->id,
        $this->branchId,
        0,
    );
});

/** Vende $qty a $price y devuelve la venta. */
function makeSale(float $qty = 4, float $price = 250, ?string $customerId = null, ?string $methodId = null)
{
    return test()->registrar->register(
        shift: test()->shift,
        lines: [['product_id' => test()->product->id, 'quantity' => $qty, 'unit_price' => $price]],
        payments: [[
            'payment_method_id' => $methodId ?? test()->cashMethod->id,
            'amount' => $qty * $price,
        ]],
        customerId: $customerId,
    );
}

// =============================================================
// Devolucion parcial
// =============================================================

describe('devolucion parcial', function () {
    it('emite una nota de credito por lo devuelto', function () {
        $sale = makeSale(4, 250);

        $note = $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );

        expect($note->total)->toBe(500.0)
            ->and($note->folio)->toStartWith('NC-')
            ->and($note->items)->toHaveCount(1)
            ->and($note->items->first()->quantity)->toBe(2.0);
    });

    it('no toca la venta original', function () {
        $sale = makeSale(4, 250);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );

        // El corte del dia en que se vendio tiene que seguir siendo el
        // que fue: la devolucion es un documento aparte.
        expect($sale->fresh()->total)->toBe(1000.0)
            ->and($sale->fresh()->status)->toBe('completed');
    });

    it('regresa la mercancia al inventario', function () {
        $sale = makeSale(4, 250);

        expect($this->product->fresh()->stock($this->branchId))->toBe(46.0);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );

        expect($this->product->fresh()->stock($this->branchId))->toBe(48.0);
    });

    it('no regresa al estante lo que viene dañado', function () {
        $sale = makeSale(4, 250);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Llego con un agujero',
            restock: false,
        );

        // Devolver mercancia rota al estante seria inventar existencia
        // vendible que no existe.
        expect($this->product->fresh()->stock($this->branchId))->toBe(46.0);
    });

    it('saca el dinero de la cuenta', function () {
        $account = Account::where('name', 'Caja')->first();
        $sale = makeSale(4, 250);

        $before = $account->fresh()->balance;

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );

        expect($account->fresh()->balance)->toBe($before - 500);
    });

    it('deja saldo a favor en vez de dinero cuando asi se pide', function () {
        $account = Account::where('name', 'Caja')->first();
        $cliente = Customer::create(['name' => 'Rosa']);
        $sale = makeSale(4, 250, $cliente->id);

        $before = $account->fresh()->balance;

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Va a cambiarla despues',
            type: CreditNote::CREDIT,
        );

        // El saldo del cliente en negativo es exactamente lo que
        // significa tener dinero a favor en el negocio.
        expect($cliente->fresh()->balance)->toBe(-500.0)
            ->and($account->fresh()->balance)->toBe($before);
    });

    it('no deja dejar saldo a favor sin cliente', function () {
        $sale = makeSale(4, 250);

        expect(fn () => $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Cambio de opinion',
            type: CreditNote::CREDIT,
        ))->toThrow(RuntimeException::class, 'cliente identificado');
    });

    it('baja la deuda de una venta a credito', function () {
        $cliente = Customer::create([
            'name' => 'Rosa', 'credit_enabled' => true, 'credit_limit' => 5000,
        ]);

        $sale = makeSale(4, 250, $cliente->id, $this->creditMethod->id);

        expect($cliente->fresh()->balance)->toBe(1000.0);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Devolvio dos',
            type: CreditNote::CREDIT,
        );

        expect($cliente->fresh()->balance)->toBe(500.0);
    });
});

// =============================================================
// Cuanto se puede devolver
// =============================================================

describe('limites', function () {
    it('no deja devolver mas de lo que se vendio', function () {
        $sale = makeSale(4, 250);

        expect(fn () => $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 5]],
            reason: 'Devolucion',
        ))->toThrow(RuntimeException::class, 'solo quedan 4');
    });

    it('descuenta lo que ya se devolvio antes', function () {
        $sale = makeSale(4, 250);
        $item = $sale->items->first();

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $item->id, 'quantity' => 3]],
            reason: 'Primera devolucion',
        );

        expect($item->fresh()->returnableQuantity())->toBe(1.0);

        // Sin contar la primera, el mismo producto se podria devolver
        // dos veces y salir dinero dos veces por lo mismo.
        expect(fn () => $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $item->id, 'quantity' => 2]],
            reason: 'Segunda devolucion',
        ))->toThrow(RuntimeException::class, 'solo quedan 1');
    });

    it('rechaza una linea que no es de esa venta', function () {
        $sale = makeSale(4, 250);
        $otra = makeSale(1, 250);

        expect(fn () => $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $otra->items->first()->id, 'quantity' => 1]],
            reason: 'Devolucion',
        ))->toThrow(RuntimeException::class, 'no pertenece a la venta');
    });

    it('rechaza una devolucion sin lineas', function () {
        $sale = makeSale(4, 250);

        expect(fn () => $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 0]],
            reason: 'Devolucion',
        ))->toThrow(RuntimeException::class, 'que se devuelve');
    });

    it('no deja devolver de una venta anulada', function () {
        $sale = makeSale(4, 250);
        $this->registrar->cancel($sale, 'Se anulo por error de captura');

        expect(fn () => $this->returns->register(
            sale: $sale->fresh(),
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            reason: 'Devolucion',
        ))->toThrow(RuntimeException::class, 'esta anulada');
    });
});

// =============================================================
// Precio que se devuelve
// =============================================================

describe('cuanto se devuelve', function () {
    it('devuelve lo que se pago, no el precio de lista', function () {
        Promotion::create([
            'name' => '2x1',
            'type' => 'nxm',
            'applies_to_all' => true,
            'buy_quantity' => 2,
            'get_quantity' => 1,
        ]);

        // 4 a 250 con 2x1: se pagaron 500, o sea 125 por unidad.
        $sale = makeSale(4, 250);
        expect($sale->total)->toBe(500.0);

        $note = $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Devolvio dos',
        );

        // Devolver al precio de lista seria regresarle 500 por algo que
        // le costo 250: el negocio pagaria por la devolucion.
        expect($note->total)->toBe(250.0);
    });

    it('descuenta el impuesto de lo devuelto', function () {
        $this->product->update(['tax_id' => Tax::where('rate', 15)->value('id')]);

        $sale = makeSale(2, 115);

        $note = $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            reason: 'Devolvio una',
        );

        expect($note->total)->toBe(115.0)
            ->and($note->tax)->toBe(15.0)
            ->and($note->subtotal)->toBe(100.0);
    });

    it('guarda el costo de lo que volvio', function () {
        $sale = makeSale(4, 250);

        $note = $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Devolvio dos',
        );

        // Sin el costo, la utilidad del periodo se quedaria con el de
        // una mercancia que volvio al estante.
        expect($note->cost_total)->toBe(200.0);
    });
});

// =============================================================
// Anulacion
// =============================================================

describe('anulacion', function () {
    it('saca de la cuenta el dinero que habia entrado', function () {
        $account = Account::where('name', 'Caja')->first();
        $before = $account->fresh()->balance;

        $sale = makeSale(4, 250);
        expect($account->fresh()->balance)->toBe($before + 1000);

        $this->registrar->cancel($sale, 'El cliente se arrepintio');

        // Sin esto la cuenta mostraria un dinero que ya no esta en el
        // cajon y el corte del dia no cuadraria.
        expect($account->fresh()->balance)->toBe($before);
    });

    it('descuenta del cambio lo que salio al cobrar', function () {
        $account = Account::where('name', 'Caja')->first();
        $before = $account->fresh()->balance;

        // Se cobran 1000 con un billete de 1200: entraron 1000 netos.
        $sale = $this->registrar->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 250]],
            payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 1200]],
        );

        expect($sale->change)->toBe(200.0);

        $this->registrar->cancel($sale, 'El cliente se arrepintio');

        expect($account->fresh()->balance)->toBe($before);
    });

    it('regresa la mercancia', function () {
        $sale = makeSale(4, 250);

        $this->registrar->cancel($sale, 'El cliente se arrepintio');

        expect($this->product->fresh()->stock($this->branchId))->toBe(50.0);
    });

    it('le quita al cliente el credito que se le cargo', function () {
        $cliente = Customer::create([
            'name' => 'Rosa', 'credit_enabled' => true, 'credit_limit' => 5000,
        ]);

        $sale = makeSale(4, 250, $cliente->id, $this->creditMethod->id);
        expect($cliente->fresh()->balance)->toBe(1000.0);

        $this->registrar->cancel($sale, 'Error de captura del cajero');

        expect($cliente->fresh()->balance)->toBe(0.0);
    });

    it('no regresa dos veces lo que ya se habia devuelto', function () {
        $account = Account::where('name', 'Caja')->first();
        $before = $account->fresh()->balance;

        $sale = makeSale(4, 250);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            reason: 'Devolvio dos',
        );

        $this->registrar->cancel($sale->fresh(), 'Al final devolvio todo');

        // Ni la mercancia ni el dinero se mueven dos veces por lo mismo.
        expect($this->product->fresh()->stock($this->branchId))->toBe(50.0)
            ->and($account->fresh()->balance)->toBe($before);
    });

    it('no se puede anular dos veces', function () {
        $sale = makeSale(4, 250);
        $this->registrar->cancel($sale, 'El cliente se arrepintio');

        expect(fn () => $this->registrar->cancel($sale->fresh(), 'Otra vez'))
            ->toThrow(RuntimeException::class, 'ya estaba anulada');
    });
});

// =============================================================
// Devolver todo de una
// =============================================================

describe('devolver todo', function () {
    it('arma la devolucion con la venta entera', function () {
        $sale = makeSale(4, 250);

        $note = $this->returns->returnEverything($sale, 'Se arrepintio de todo');

        expect($note->total)->toBe(1000.0)
            ->and($this->product->fresh()->stock($this->branchId))->toBe(50.0);
    });

    it('solo devuelve lo que quedaba pendiente', function () {
        $sale = makeSale(4, 250);

        $this->returns->register(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
            reason: 'Devolvio tres',
        );

        $note = $this->returns->returnEverything($sale->fresh(), 'Devolvio la ultima');

        expect($note->total)->toBe(250.0);
    });

    it('no emite nada si ya se devolvio todo', function () {
        $sale = makeSale(4, 250);

        $this->returns->returnEverything($sale, 'Devolvio todo');

        expect($this->returns->returnEverything($sale->fresh(), 'Otra vez'))->toBeNull();
    });
});
