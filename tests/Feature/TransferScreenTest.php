<?php

use App\Livewire\Transfers\Index;
use App\Livewire\Transfers\Show;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Services\InventoryManager;
use App\Services\TransferManager;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->centro = $this->context['setup']['branch'];
    $this->norte = Branch::create(['name' => 'Sucursal norte', 'code' => 'NOR']);

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Taladro',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 500,
    ]);

    app(InventoryManager::class)->move($this->product, $this->centro->id, 20, 'initial');
});

function makeTransfer(float $qty = 5): StockTransfer
{
    return app(TransferManager::class)->create(
        fromBranchId: test()->centro->id,
        toBranchId: test()->norte->id,
        lines: [['product_id' => test()->product->id, 'quantity' => $qty]],
    );
}

describe('acceso', function () {
    it('niega la pantalla a quien no ve inventario', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'inventory.view' => false],
        ]);

        $this->get(route('transfers'))->assertForbidden();
    });

    it('deja mirar pero no crear a quien solo puede ver', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'inventory.view' => true],
        ]);

        $this->get(route('transfers'))->assertOk()->assertDontSee('+ Traspaso');

        Livewire::test(Index::class)->call('create')->assertForbidden();
    });
});

describe('alta', function () {
    it('crea el traspaso y lleva a su detalle', function () {
        Livewire::test(Index::class)
            ->call('create')
            ->set('toBranchId', $this->norte->id)
            ->call('addProduct', $this->product->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('transfers.show', StockTransfer::first()));

        expect(StockTransfer::first()->status)->toBe(StockTransfer::DRAFT);
    });

    it('no deja mandar a la misma sucursal', function () {
        Livewire::test(Index::class)
            ->call('create')
            ->set('toBranchId', $this->centro->id)
            ->call('addProduct', $this->product->id)
            ->call('save')
            ->assertHasErrors(['fromBranchId']);
    });

    it('exige al menos un producto', function () {
        Livewire::test(Index::class)
            ->call('create')
            ->set('toBranchId', $this->norte->id)
            ->call('save')
            ->assertHasErrors(['lines']);
    });

    it('escanear dos veces suma cantidad en vez de repetir la linea', function () {
        $component = Livewire::test(Index::class)
            ->call('create')
            ->call('addProduct', $this->product->id)
            ->call('addProduct', $this->product->id);

        // El numero vuelve del navegador como entero cuando es redondo;
        // lo que importa es la cantidad, no su tipo.
        expect($component->get('lines'))->toHaveCount(1)
            ->and((float) $component->get('lines')[$this->product->id]['quantity'])->toBe(2.0);
    });

    it('muestra la existencia del origen de cada linea', function () {
        $component = Livewire::test(Index::class)
            ->call('create')
            ->call('addProduct', $this->product->id);

        // Nadie deberia escribir de memoria un numero que la tienda no
        // tiene.
        expect($component->instance()->availability[$this->product->id])->toBe(20.0);
    });

    it('no ofrece productos que no manejan stock', function () {
        Product::create([
            'sku' => 'S-1',
            'name' => 'Taladro a domicilio',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'track_stock' => false,
        ]);

        $component = Livewire::test(Index::class)
            ->call('create')
            ->set('productSearch', 'Taladro');

        expect($component->instance()->results->pluck('name')->all())->toBe(['Taladro']);
    });

    it('avisa cuando solo hay una sucursal', function () {
        $this->norte->delete();

        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('showForm', false)
            ->assertSee('Solo tienes una sucursal');
    });
});

describe('envio y recepcion', function () {
    it('envia y deja la mercancia en camino', function () {
        $transfer = makeTransfer(5);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('send')
            ->assertSee('En camino');

        expect($transfer->fresh()->status)->toBe(StockTransfer::SENT)
            ->and((float) Inventory::where('branch_id', $this->centro->id)->value('quantity'))
            ->toBe(15.0);
    });

    it('avisa sin reventar cuando ya no hay existencia', function () {
        $transfer = makeTransfer(20);

        app(InventoryManager::class)->move($this->product, $this->centro->id, -15, 'sale');

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('send');

        expect($transfer->fresh()->status)->toBe(StockTransfer::DRAFT);
    });

    it('recibe completo por defecto', function () {
        $transfer = app(TransferManager::class)->send(makeTransfer(5));

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('openReceive')
            ->assertSet("receivedLines.{$transfer->items->first()->id}", 5.0)
            ->call('receive');

        expect((float) Inventory::where('branch_id', $this->norte->id)->value('quantity'))
            ->toBe(5.0);
    });

    it('deja recibir menos y lo deja a la vista', function () {
        $transfer = app(TransferManager::class)->send(makeTransfer(5));
        $itemId = $transfer->items->first()->id;

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('openReceive')
            ->set("receivedLines.{$itemId}", 3)
            ->call('receive')
            ->assertSee('Faltaron 2 unidades');

        expect((float) Inventory::where('branch_id', $this->norte->id)->value('quantity'))
            ->toBe(3.0);
    });

    it('manda y recibe de un clic', function () {
        $transfer = makeTransfer(5);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('sendAndReceive');

        expect($transfer->fresh()->status)->toBe(StockTransfer::RECEIVED)
            ->and((float) Inventory::where('branch_id', $this->norte->id)->value('quantity'))
            ->toBe(5.0);
    });

    it('solo ofrece la accion que toca', function () {
        $transfer = makeTransfer(5);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->assertSee('Enviar')
            ->assertDontSee('Recibir');

        app(TransferManager::class)->send($transfer);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->assertSee('Recibir')
            ->assertDontSee('Enviar y recibir');
    });

    it('no deja mover el traspaso sin el permiso de ajustar', function () {
        $transfer = makeTransfer(5);

        $this->context['user']->update(['permissions_override' => ['inventory.adjust' => false]]);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->call('send')
            ->assertForbidden();
    });
});

describe('cancelacion', function () {
    it('cancela y regresa la mercancia que iba en camino', function () {
        $transfer = app(TransferManager::class)->send(makeTransfer(5));

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->set('cancelReason', 'Se cayo el envio de hoy')
            ->call('cancel')
            ->assertHasNoErrors();

        expect($transfer->fresh()->status)->toBe(StockTransfer::CANCELLED)
            ->and((float) Inventory::where('branch_id', $this->centro->id)->value('quantity'))
            ->toBe(20.0);
    });

    it('exige un motivo', function () {
        $transfer = makeTransfer(5);

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->set('cancelReason', '')
            ->call('cancel')
            ->assertHasErrors(['cancelReason']);

        expect($transfer->fresh()->status)->toBe(StockTransfer::DRAFT);
    });

    it('avisa sin reventar si ya se recibio', function () {
        $transfer = app(TransferManager::class)->sendAndReceive(makeTransfer(5));

        Livewire::test(Show::class, ['transferId' => $transfer->id])
            ->set('cancelReason', 'Me equivoque de sucursal')
            ->call('cancel');

        expect($transfer->fresh()->status)->toBe(StockTransfer::RECEIVED);
    });
});
