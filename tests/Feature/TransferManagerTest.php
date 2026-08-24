<?php

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Services\InventoryManager;
use App\Services\TransferManager;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->centro = $this->context['setup']['branch'];
    $this->norte = Branch::create(['name' => 'Sucursal norte', 'code' => 'NOR']);

    $this->transfers = app(TransferManager::class);
    $this->inventory = app(InventoryManager::class);

    $this->product = Product::create([
        'sku' => 'P-1',
        'name' => 'Taladro',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 500,
    ]);

    $this->inventory->move($this->product, $this->centro->id, 20, 'initial');
});

/** Arma un traspaso de $qty taladros del centro al norte. */
function transfer(float $qty = 5): StockTransfer
{
    return test()->transfers->create(
        fromBranchId: test()->centro->id,
        toBranchId: test()->norte->id,
        lines: [['product_id' => test()->product->id, 'quantity' => $qty]],
    );
}

function stockAt(string $branchId): float
{
    return (float) (Inventory::where('branch_id', $branchId)
        ->where('product_id', test()->product->id)
        ->value('quantity') ?? 0);
}

// =============================================================
// Alta
// =============================================================

describe('armar el traspaso', function () {
    it('nace en borrador y no mueve nada', function () {
        $t = transfer(5);

        expect($t->status)->toBe(StockTransfer::DRAFT)
            ->and($t->folio)->toStartWith('T-')
            ->and($t->items)->toHaveCount(1)
            ->and(stockAt($this->centro->id))->toBe(20.0)
            ->and(stockAt($this->norte->id))->toBe(0.0);
    });

    it('no deja traspasar a la misma sucursal', function () {
        expect(fn () => $this->transfers->create(
            fromBranchId: $this->centro->id,
            toBranchId: $this->centro->id,
            lines: [['product_id' => $this->product->id, 'quantity' => 1]],
        ))->toThrow(RuntimeException::class, 'sucursales distintas');
    });

    it('rechaza un traspaso sin productos', function () {
        expect(fn () => $this->transfers->create(
            fromBranchId: $this->centro->id,
            toBranchId: $this->norte->id,
            lines: [['product_id' => $this->product->id, 'quantity' => 0]],
        ))->toThrow(RuntimeException::class, 'no tiene productos');
    });

    it('rechaza un producto que no maneja stock', function () {
        $servicio = Product::create([
            'sku' => 'S-1',
            'name' => 'Instalacion',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
            'track_stock' => false,
        ]);

        expect(fn () => $this->transfers->create(
            fromBranchId: $this->centro->id,
            toBranchId: $this->norte->id,
            lines: [['product_id' => $servicio->id, 'quantity' => 1]],
        ))->toThrow(RuntimeException::class, 'no maneja stock');
    });

    it('deja cambiar las lineas mientras siga en borrador', function () {
        $t = transfer(5);

        $this->transfers->updateLines($t, [
            ['product_id' => $this->product->id, 'quantity' => 8],
        ]);

        expect($t->fresh('items')->items->first()->quantity_sent)->toBe(8.0);
    });

    it('no deja cambiar las lineas de uno que ya salio', function () {
        $t = $this->transfers->send(transfer(5));

        expect(fn () => $this->transfers->updateLines($t, [
            ['product_id' => $this->product->id, 'quantity' => 8],
        ]))->toThrow(RuntimeException::class, 'ya salio');
    });
});

// =============================================================
// Salida
// =============================================================

describe('salida', function () {
    it('descuenta del origen y no suma al destino todavia', function () {
        $this->transfers->send(transfer(5));

        // La mercancia va en camino: no esta en ninguna de las dos.
        // Sumarla ya haria que el norte la ofrezca sin tenerla.
        expect(stockAt($this->centro->id))->toBe(15.0)
            ->and(stockAt($this->norte->id))->toBe(0.0);
    });

    it('marca el traspaso como en camino', function () {
        $t = $this->transfers->send(transfer(5));

        expect($t->status)->toBe(StockTransfer::SENT)
            ->and($t->isInTransit())->toBeTrue()
            ->and($t->sent_at)->not->toBeNull();
    });

    it('no deja mandar mas de lo que hay', function () {
        $t = transfer(50);

        expect(fn () => $this->transfers->send($t))
            ->toThrow(RuntimeException::class, 'No hay suficiente');
    });

    it('comprueba la existencia al mandar, no al armar', function () {
        $t = transfer(20);

        // Entre que alguien prepara el traspaso y lo manda, la tienda
        // sigue vendiendo.
        $this->inventory->move($this->product, $this->centro->id, -15, 'sale');

        expect(fn () => $this->transfers->send($t))
            ->toThrow(RuntimeException::class, 'quedan 5');
    });

    it('no deja mandar dos veces', function () {
        $t = $this->transfers->send(transfer(5));

        expect(fn () => $this->transfers->send($t))
            ->toThrow(RuntimeException::class, 'ya no esta en borrador');
    });

    it('deja el movimiento en el kardex del origen', function () {
        $t = $this->transfers->send(transfer(5));

        $movement = InventoryMovement::where('reference_id', $t->id)->first();

        expect($movement->type)->toBe('transfer_out')
            ->and($movement->quantity)->toBe(-5.0)
            ->and($movement->branch_id)->toBe($this->centro->id);
    });
});

// =============================================================
// Llegada
// =============================================================

describe('llegada', function () {
    it('suma al destino lo que llego', function () {
        $t = $this->transfers->receive($this->transfers->send(transfer(5)));

        expect(stockAt($this->centro->id))->toBe(15.0)
            ->and(stockAt($this->norte->id))->toBe(5.0)
            ->and($t->status)->toBe(StockTransfer::RECEIVED);
    });

    it('admite recibir menos de lo que salio', function () {
        $t = $this->transfers->send(transfer(5));
        $itemId = $t->items->first()->id;

        $t = $this->transfers->receive($t, [$itemId => 4]);

        // Lo que falta ya salio del origen y nunca llego: eso es
        // exactamente lo que paso, y las dos existencias lo cuentan.
        expect(stockAt($this->centro->id))->toBe(15.0)
            ->and(stockAt($this->norte->id))->toBe(4.0)
            ->and($t->shortfall())->toBe(1.0);
    });

    it('escribe el faltante como se lee', function () {
        $t = $this->transfers->send(transfer(5));
        $itemId = $t->items->first()->id;

        // "Faltaron 1 unidades" delata que nadie miro la pantalla.
        expect($this->transfers->receive($t, [$itemId => 4])->shortfallLabel())
            ->toBe('Falto 1 unidad');

        $otro = $this->transfers->send(transfer(5));
        expect($this->transfers->receive($otro, [$otro->items->first()->id => 3])->shortfallLabel())
            ->toBe('Faltaron 2 unidades');
    });

    it('no deja recibir mas de lo que salio', function () {
        $t = $this->transfers->send(transfer(5));
        $itemId = $t->items->first()->id;

        $t = $this->transfers->receive($t, [$itemId => 99]);

        expect(stockAt($this->norte->id))->toBe(5.0);
    });

    it('no deja recibir uno que todavia no sale', function () {
        expect(fn () => $this->transfers->receive(transfer(5)))
            ->toThrow(RuntimeException::class, 'va en camino');
    });

    it('no deja recibir dos veces', function () {
        $t = $this->transfers->receive($this->transfers->send(transfer(5)));

        expect(fn () => $this->transfers->receive($t))
            ->toThrow(RuntimeException::class, 'va en camino');
    });

    it('manda y recibe de una sola vez', function () {
        $t = $this->transfers->sendAndReceive(transfer(5));

        expect($t->status)->toBe(StockTransfer::RECEIVED)
            ->and(stockAt($this->centro->id))->toBe(15.0)
            ->and(stockAt($this->norte->id))->toBe(5.0);
    });
});

// =============================================================
// Costo
// =============================================================

describe('costo', function () {
    it('el destino hereda el costo del origen', function () {
        // El centro compro a 600, asi que su promedio subio.
        $this->inventory->move($this->product, $this->centro->id, 20, 'purchase');
        Inventory::where('branch_id', $this->centro->id)->update(['avg_cost' => 600]);

        $this->transfers->sendAndReceive(transfer(5));

        // Sin el costo del origen, el destino heredaria el del catalogo
        // y el inventario valorizado saldria mal.
        expect((float) Inventory::where('branch_id', $this->norte->id)->value('avg_cost'))
            ->toBe(600.0);
    });

    it('mezcla el costo cuando el destino ya tenia mercancia', function () {
        $this->inventory->move($this->product, $this->norte->id, 5, 'initial');
        Inventory::where('branch_id', $this->norte->id)->update(['avg_cost' => 400]);
        Inventory::where('branch_id', $this->centro->id)->update(['avg_cost' => 600]);

        $this->transfers->sendAndReceive(transfer(5));

        // 5 a 400 mas 5 a 600 dan 500 de promedio.
        expect((float) Inventory::where('branch_id', $this->norte->id)->value('avg_cost'))
            ->toBe(500.0);
    });

    it('guarda el valor de lo enviado', function () {
        Inventory::where('branch_id', $this->centro->id)->update(['avg_cost' => 480]);

        $t = $this->transfers->send(transfer(5));

        expect($t->total_cost)->toBe(2400.0);
    });
});

// =============================================================
// Cancelacion
// =============================================================

describe('cancelacion', function () {
    it('cancela un borrador sin mover nada', function () {
        $t = $this->transfers->cancel(transfer(5), 'Ya no hace falta');

        expect($t->status)->toBe(StockTransfer::CANCELLED)
            ->and(stockAt($this->centro->id))->toBe(20.0);
    });

    it('regresa al origen la mercancia que iba en camino', function () {
        $t = $this->transfers->send(transfer(5));
        expect(stockAt($this->centro->id))->toBe(15.0);

        $this->transfers->cancel($t, 'Se cayo el envio');

        // La mercancia esta en algun lado, y ese lado es de donde salio.
        expect(stockAt($this->centro->id))->toBe(20.0)
            ->and(stockAt($this->norte->id))->toBe(0.0);
    });

    it('no deja cancelar uno ya recibido', function () {
        $t = $this->transfers->sendAndReceive(transfer(5));

        expect(fn () => $this->transfers->cancel($t, 'Me equivoque'))
            ->toThrow(RuntimeException::class, 'sentido contrario');
    });

    it('no deja cancelar dos veces', function () {
        $t = $this->transfers->cancel(transfer(5), 'Ya no hace falta');

        expect(fn () => $this->transfers->cancel($t, 'Otra vez'))
            ->toThrow(RuntimeException::class, 'ya estaba cancelado');
    });
});
