<?php

use App\Livewire\Returns\Index as ReturnsIndex;
use App\Livewire\Returns\Show as ReturnShow;
use App\Livewire\Sales\Show as SaleShow;
use App\Models\Account;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\ReturnRegistrar;
use App\Services\SaleRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();

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

    $this->sale = app(SaleRegistrar::class)->register(
        shift: $this->shift,
        lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 250]],
        payments: [['payment_method_id' => $this->cashMethod->id, 'amount' => 1000]],
    );

    $this->item = $this->sale->items->first();
});

describe('devolucion desde el ticket', function () {
    it('ofrece devolver mientras quede algo', function () {
        $this->get(route('sales.show', $this->sale))
            ->assertOk()
            ->assertSee('Devolucion');
    });

    it('no ofrece devolver una venta anulada', function () {
        app(SaleRegistrar::class)->cancel($this->sale, 'El cliente se arrepintio');

        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->assertDontSee('Registrar devolucion');
    });

    it('registra la devolucion y lleva a su documento', function () {
        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->set("returnLines.{$this->item->id}", 2)
            ->set('returnReason', 'No le quedo la talla')
            ->call('saveReturn')
            ->assertHasNoErrors()
            ->assertRedirect(route('returns.show', CreditNote::first()));

        expect(CreditNote::first()->total)->toBe(500.0);
    });

    it('el boton de todo rellena lo que queda pendiente', function () {
        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->call('returnAll')
            ->assertSet("returnLines.{$this->item->id}", 4.0);
    });

    it('exige un motivo', function () {
        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->set("returnLines.{$this->item->id}", 2)
            ->call('saveReturn')
            ->assertHasErrors(['returnReason']);

        expect(CreditNote::count())->toBe(0);
    });

    it('exige indicar que se devuelve', function () {
        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->set('returnReason', 'No le quedo la talla')
            ->call('saveReturn')
            ->assertHasErrors(['returnLines']);
    });

    it('avisa cuando se pide devolver mas de lo que queda', function () {
        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->set("returnLines.{$this->item->id}", 9)
            ->set('returnReason', 'Quiere devolver todo')
            ->call('saveReturn')
            ->assertHasErrors(['returnLines']);

        expect(CreditNote::count())->toBe(0);
    });

    it('no deja devolver sin el permiso', function () {
        $this->context['user']->update(['permissions_override' => ['sales.return' => false]]);

        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->call('openReturn')
            ->assertForbidden();
    });

    it('propone saldo a favor cuando la venta fue a credito', function () {
        $cliente = Customer::create([
            'name' => 'Rosa', 'credit_enabled' => true, 'credit_limit' => 5000,
        ]);

        $credito = app(SaleRegistrar::class)->register(
            shift: $this->shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 250]],
            payments: [[
                'payment_method_id' => PaymentMethod::where('type', 'credit')->value('id'),
                'amount' => 250,
            ]],
            customerId: $cliente->id,
        );

        // Devolver dinero de una venta que nunca lo trajo sacaria efectivo
        // de una caja que no lo recibio.
        Livewire::test(SaleShow::class, ['saleId' => $credito->id])
            ->call('openReturn')
            ->assertSet('returnType', CreditNote::CREDIT);
    });

    it('el ticket avisa de las devoluciones ya emitidas', function () {
        app(ReturnRegistrar::class)->register(
            sale: $this->sale,
            lines: [['sale_item_id' => $this->item->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );

        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->assertSee('tiene devoluciones por');
    });
});

describe('anulacion desde el ticket', function () {
    it('anula y saca el dinero de la cuenta', function () {
        $account = Account::where('name', 'Caja')->first();
        $before = $account->fresh()->balance;

        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->set('cancelReason', 'El cliente se arrepintio de la compra')
            ->call('cancel')
            ->assertHasNoErrors();

        expect($this->sale->fresh()->status)->toBe('cancelled')
            ->and($account->fresh()->balance)->toBe($before - 1000);
    });

    it('avisa en vez de reventar si ya estaba anulada', function () {
        app(SaleRegistrar::class)->cancel($this->sale, 'Se anulo por error de captura');

        Livewire::test(SaleShow::class, ['saleId' => $this->sale->id])
            ->set('cancelReason', 'Otra vez la misma')
            ->call('cancel')
            ->assertHasNoErrors();
    });
});

describe('listado de devoluciones', function () {
    beforeEach(function () {
        $this->note = app(ReturnRegistrar::class)->register(
            sale: $this->sale,
            lines: [['sale_item_id' => $this->item->id, 'quantity' => 2]],
            reason: 'No le quedo la talla',
        );
    });

    it('suma lo devuelto del periodo', function () {
        $summary = Livewire::test(ReturnsIndex::class)->instance()->summary;

        expect($summary['notes'])->toBe(1)
            ->and($summary['total'])->toBe(500.0)
            ->and($summary['refunded'])->toBe(500.0);
    });

    it('busca por folio de la venta', function () {
        Livewire::test(ReturnsIndex::class)
            ->set('search', $this->sale->folio)
            ->assertSee($this->note->folio);
    });

    it('filtra por tipo', function () {
        Livewire::test(ReturnsIndex::class)
            ->set('type', 'credit')
            ->assertDontSee($this->note->folio);
    });

    it('muestra el documento de la devolucion', function () {
        Livewire::test(ReturnShow::class, ['noteId' => $this->note->id])
            ->assertSee($this->note->folio)
            ->assertSee('Camisa')
            ->assertSee('No le quedo la talla')
            ->assertSee('Volvio al inventario');
    });

    it('niega el listado a quien no puede ver ventas', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'sales.view' => false],
        ]);

        $this->get(route('returns'))->assertForbidden();
    });
});
