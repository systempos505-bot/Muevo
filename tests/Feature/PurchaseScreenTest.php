<?php

use App\Livewire\Partners\Suppliers as SuppliersScreen;
use App\Livewire\Purchases\Form;
use App\Livewire\Purchases\Show;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\PurchaseRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant('hardware');
    $this->branchId = $this->context['setup']['branch']->id;

    $this->supplier = Supplier::create(['name' => 'Distribuidora Central', 'credit_days' => 30]);

    $this->product = Product::create([
        'sku' => 'FER-1',
        'name' => 'Martillo',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'tax_id' => Tax::where('is_default', true)->value('id'),
        'cost' => 50,
    ]);

    ProductUnit::create([
        'product_id' => $this->product->id,
        'unit_id' => $this->product->base_unit_id,
        'factor' => 1,
        'is_default' => true,
    ]);

    ProductBarcode::create([
        'product_id' => $this->product->id,
        'code' => '7509999999999',
    ]);
});

// =============================================================
// Registro de compra
// =============================================================

describe('registro', function () {
    it('agrega un producto al escanear su codigo', function () {
        Livewire::test(Form::class)
            ->set('search', '7509999999999')
            ->call('submitSearch')
            ->assertCount('lines', 1)
            ->assertSet('search', '');
    });

    it('propone el ultimo costo conocido', function () {
        $component = Livewire::test(Form::class)->call('addProduct', $this->product->id);

        expect(array_values($component->get('lines'))[0]['unit_cost'])->toBe(50.0);
    });

    it('registra la compra y mete la mercancia al inventario', function () {
        $component = Livewire::test(Form::class)->call('addProduct', $this->product->id);
        $key = array_key_first($component->get('lines'));

        $component
            ->set("lines.{$key}.quantity", 20)
            ->set("lines.{$key}.unit_cost", 45)
            ->set('supplierId', $this->supplier->id)
            ->call('save')
            ->assertHasNoErrors();

        $purchase = Purchase::sole();

        expect($purchase->total)->toBe(1035.0)
            ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(20.0)
            ->and($this->product->fresh()->cost)->toBe(45.0);
    });

    it('no deja registrar una compra sin productos', function () {
        Livewire::test(Form::class)
            ->set('supplierId', $this->supplier->id)
            ->call('save')
            ->assertHasErrors('lines');

        expect(Purchase::count())->toBe(0);
    });

    it('exige proveedor cuando la compra es a credito', function () {
        Livewire::test(Form::class)
            ->call('addProduct', $this->product->id)
            ->set('paymentType', 'credit')
            ->set('supplierId', null)
            ->call('save')
            ->assertHasErrors('supplierId');
    });

    it('muestra el costo por unidad base al comprar por caja', function () {
        $caja = Unit::where('code', 'CJA')->first();

        $boxUnit = ProductUnit::create([
            'product_id' => $this->product->id,
            'unit_id' => $caja->id,
            'factor' => 24,
            'is_purchase' => true,
        ]);

        $component = Livewire::test(Form::class)->call('addProduct', $this->product->id);
        $key = array_key_first($component->get('lines'));

        // Al comprar se prefiere la presentacion de compra.
        expect(array_values($component->get('lines'))[0]['product_unit_id'])->toBe($boxUnit->id);

        $component->set("lines.{$key}.quantity", 2)->set("lines.{$key}.unit_cost", 240);

        // 2 cajas de 24 a 240: 48 piezas a 10 cada una.
        $line = array_values($component->get('lines'))[0];
        expect($component->instance()->baseCostFor($line))->toBe(10.0);
    });

    it('muestra el error del motor cuando falta el lote', function () {
        $this->product->update(['track_lots' => true]);

        Livewire::test(Form::class)
            ->call('addProduct', $this->product->id)
            ->set('supplierId', $this->supplier->id)
            ->call('save')
            ->assertHasErrors('lines');

        expect(Purchase::count())->toBe(0);
    });
});

// =============================================================
// Abonos y anulacion
// =============================================================

describe('detalle', function () {
    beforeEach(function () {
        $this->purchase = app(PurchaseRegistrar::class)->register(
            branchId: $this->branchId,
            lines: [[
                'product_id' => $this->product->id,
                'quantity' => 20,
                'unit_cost' => 45,
            ]],
            supplierId: $this->supplier->id,
            paymentType: 'credit',
            dueDate: '2026-12-31',
        );
    });

    it('muestra la compra con su saldo', function () {
        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->assertSee($this->purchase->folio)
            ->assertSee('Martillo')
            ->assertSet('paymentAmount', 1035.0);
    });

    it('registra un abono y baja el saldo', function () {
        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->set('paymentAmount', 500)
            ->call('pay')
            ->assertHasNoErrors();

        expect($this->purchase->fresh()->paid)->toBe(500.0)
            ->and($this->supplier->fresh()->balance)->toBe(535.0);
    });

    it('no deja abonar mas que el saldo', function () {
        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->set('paymentAmount', 5000)
            ->call('pay')
            ->assertHasErrors('paymentAmount');
    });

    it('anula la compra y saca la mercancia', function () {
        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->set('cancelReason', 'Llego mercancia equivocada')
            ->call('cancel')
            ->assertHasNoErrors();

        expect($this->purchase->fresh()->status)->toBe('cancelled')
            ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(0.0)
            ->and($this->supplier->fresh()->balance)->toBe(0.0);
    });

    it('exige motivo para anular', function () {
        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->set('cancelReason', '')
            ->call('cancel')
            ->assertHasErrors('cancelReason');
    });

    it('no deja anular sin el permiso', function () {
        $this->context['user']->update(['permissions_override' => ['purchases.void' => false]]);

        Livewire::test(Show::class, ['purchaseId' => $this->purchase->id])
            ->set('cancelReason', 'Motivo suficiente')
            ->call('cancel')
            ->assertForbidden();
    });
});

// =============================================================
// Proveedores
// =============================================================

describe('proveedores', function () {
    it('crea un proveedor', function () {
        Livewire::test(SuppliersScreen::class)
            ->call('create')
            ->set('name', 'Ferreteria Mayorista')
            ->set('phone', '2222-3333')
            ->set('creditDays', 15)
            ->call('save')
            ->assertHasNoErrors();

        $supplier = Supplier::where('name', 'Ferreteria Mayorista')->first();

        expect($supplier)->not->toBeNull()
            ->and($supplier->credit_days)->toBe(15);
    });

    it('edita un proveedor', function () {
        Livewire::test(SuppliersScreen::class)
            ->call('edit', $this->supplier->id)
            ->assertSet('name', 'Distribuidora Central')
            ->set('contactName', 'Luis Perez')
            ->call('save');

        expect($this->supplier->fresh()->contact_name)->toBe('Luis Perez');
    });

    it('no deja desactivar a un proveedor con saldo', function () {
        $this->supplier->update(['balance' => 500]);

        Livewire::test(SuppliersScreen::class)->call('toggleStatus', $this->supplier->id);

        // Esconderlo haria que la cuenta por pagar se pierda de vista.
        expect($this->supplier->fresh()->status)->toBe('active');
    });

    it('desactiva a un proveedor sin saldo', function () {
        Livewire::test(SuppliersScreen::class)->call('toggleStatus', $this->supplier->id);

        expect($this->supplier->fresh()->status)->toBe('inactive');
    });

    it('suma el total por pagar', function () {
        $this->supplier->update(['balance' => 500]);
        Supplier::create(['name' => 'Otro', 'balance' => 300]);

        expect(Livewire::test(SuppliersScreen::class)->get('totalPayable'))->toBe(800.0);
    });

    it('filtra por proveedores con saldo', function () {
        $this->supplier->update(['balance' => 500]);
        Supplier::create(['name' => 'Sin deuda']);

        Livewire::test(SuppliersScreen::class)
            ->set('filter', 'debt')
            ->assertSee('Distribuidora Central')
            ->assertDontSee('Sin deuda');
    });
});
