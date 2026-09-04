<?php

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\QuoteRegistrar;

beforeEach(function () {
    $this->context = actingAsTenant('general');
    $this->branchId = $this->context['setup']['branch']->id;
    $this->terminalId = $this->context['setup']['terminal']->id;

    $this->inventory = app(InventoryManager::class);
    $this->quotes = app(QuoteRegistrar::class);
    $this->cash = app(CashRegister::class);

    $this->cashMethod = PaymentMethod::where('code', 'EFE')->first();

    // Impuesto 15% incluido en el precio: precio 115 => neto 100, iva 15.
    $this->product = Product::create([
        'sku' => 'ABA-1',
        'name' => 'Aceite vegetal 1L',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'tax_id' => Tax::where('is_default', true)->value('id'),
        'cost' => 60,
    ]);

    $this->inventory->move($this->product, $this->branchId, 100, 'initial', 'Carga inicial');
});

function quoteLine(Product $product, float $qty, float $price, float $discount = 0): array
{
    return [
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => $price,
        'discount' => $discount,
    ];
}

function makeQuote(array $overrides = []): Quote
{
    return app(QuoteRegistrar::class)->create(
        branchId: $overrides['branchId'] ?? test()->branchId,
        lines: $overrides['lines'] ?? [quoteLine(test()->product, 10, 115)],
        customerName: $overrides['customerName'] ?? 'Bodega La Esquina',
        customerId: $overrides['customerId'] ?? null,
        customerPhone: $overrides['customerPhone'] ?? '999888777',
        validUntil: $overrides['validUntil'] ?? null,
        notes: $overrides['notes'] ?? null,
    );
}

// =============================================================
// Alta
// =============================================================

it('crea una cotizacion con folio propio y totales cuadrados', function () {
    $quote = makeQuote();

    expect($quote->folio)->toStartWith('COT-')
        ->and($quote->status)->toBe(Quote::PENDING)
        ->and($quote->subtotal)->toBe(1150.0)
        ->and($quote->total)->toBe(1150.0)
        ->and($quote->tax)->toBe(150.0)   // 1150 - (1150 / 1.15)
        ->and($quote->items)->toHaveCount(1);

    // Neto mas impuesto tiene que dar el total, al centavo.
    expect(round($quote->items->sum('net') + $quote->tax, 2))->toBe($quote->total);
});

it('no mueve inventario al cotizar', function () {
    makeQuote();

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(100.0);
});

it('rechaza una cotizacion sin productos', function () {
    expect(fn () => makeQuote(['lines' => []]))
        ->toThrow(RuntimeException::class, 'no tiene productos');
});

it('rechaza una cantidad en cero', function () {
    expect(fn () => makeQuote(['lines' => [quoteLine($this->product, 0, 115)]]))
        ->toThrow(RuntimeException::class, 'mayor que cero');
});

it('guarda copia del nombre y el sku del producto', function () {
    $item = makeQuote()->items->first();

    expect($item->description)->toBe('Aceite vegetal 1L')
        ->and($item->sku)->toBe('ABA-1');

    $this->product->update(['name' => 'Otro nombre', 'sku' => 'CAMBIADO']);

    expect($item->fresh()->description)->toBe('Aceite vegetal 1L')
        ->and($item->fresh()->sku)->toBe('ABA-1');
});

it('toma el nombre del cliente registrado cuando se elige uno', function () {
    $customer = Customer::create(['name' => 'Distribuidora del Sur']);

    $quote = makeQuote(['customerId' => $customer->id, 'customerName' => 'lo que sea']);

    expect($quote->customer_name)->toBe('Distribuidora del Sur')
        ->and($quote->customerLabel())->toBe('Distribuidora del Sur');
});

it('vence a los quince dias si no se indica hasta cuando', function () {
    expect(makeQuote()->valid_until->toDateString())
        ->toBe(now()->addDays(15)->toDateString());
});

it('aplica el descuento de la linea', function () {
    $quote = makeQuote(['lines' => [quoteLine($this->product, 10, 115, 150)]]);

    expect($quote->subtotal)->toBe(1150.0)
        ->and($quote->discount)->toBe(150.0)
        ->and($quote->total)->toBe(1000.0);
});

// =============================================================
// Edicion
// =============================================================

it('reemplaza las lineas al editar', function () {
    $quote = makeQuote();

    $this->quotes->update(
        quote: $quote,
        lines: [quoteLine($this->product, 4, 115)],
        customerName: 'Bodega La Esquina',
    );

    $quote->refresh();

    expect($quote->items)->toHaveCount(1)
        ->and($quote->items->first()->quantity)->toBe(4.0)
        ->and($quote->total)->toBe(460.0);
});

it('no deja editar una cotizacion que ya fue respondida', function () {
    $quote = $this->quotes->approve(makeQuote());

    expect(fn () => $this->quotes->update($quote, [quoteLine($this->product, 1, 115)], 'X'))
        ->toThrow(RuntimeException::class, 'pendiente');
});

// =============================================================
// Respuesta del cliente
// =============================================================

it('aprueba una cotizacion pendiente', function () {
    $quote = $this->quotes->approve(makeQuote());

    expect($quote->status)->toBe(Quote::APPROVED)
        ->and($quote->answered_at)->not->toBeNull()
        ->and($quote->answered_by)->toBe($this->context['user']->id);
});

it('no aprueba dos veces', function () {
    $quote = $this->quotes->approve(makeQuote());

    expect(fn () => $this->quotes->approve($quote))
        ->toThrow(RuntimeException::class, 'pendiente');
});

it('rechaza guardando el motivo', function () {
    $quote = $this->quotes->reject(makeQuote(), 'El cliente encontro mas barato');

    expect($quote->status)->toBe(Quote::REJECTED)
        ->and($quote->reject_reason)->toBe('El cliente encontro mas barato');
});

it('reabre una cotizacion rechazada', function () {
    $quote = $this->quotes->reject(makeQuote(), 'Lo pensara');
    $quote = $this->quotes->reopen($quote);

    expect($quote->status)->toBe(Quote::PENDING)
        ->and($quote->reject_reason)->toBeNull()
        ->and($quote->isEditable())->toBeTrue();
});

it('solo reabre las rechazadas', function () {
    expect(fn () => $this->quotes->reopen(makeQuote()))
        ->toThrow(RuntimeException::class, 'rechazada');
});

// =============================================================
// Vencimiento
// =============================================================

it('marca como vencida la que paso su fecha', function () {
    $quote = makeQuote(['validUntil' => now()->subDay()->toDateString()]);

    expect($quote->isExpired())->toBeTrue()
        ->and($quote->statusLabel())->toBe('Vencida');
});

it('sigue vigente el mismo dia en que vence', function () {
    $quote = makeQuote(['validUntil' => now()->toDateString()]);

    expect($quote->isExpired())->toBeFalse();
});

it('extiende la vigencia sin tocar los precios', function () {
    $quote = makeQuote(['validUntil' => now()->subDay()->toDateString()]);
    $total = $quote->total;

    $quote = $this->quotes->extend($quote, now()->addDays(10)->toDateString());

    expect($quote->isExpired())->toBeFalse()
        ->and($quote->total)->toBe($total);
});

// =============================================================
// Conversion en venta
// =============================================================

it('convierte la cotizacion en una venta con los precios pactados', function () {
    $quote = $this->quotes->approve(makeQuote());
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    $sale = $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 1150],
    ]);

    expect($sale)->toBeInstanceOf(Sale::class)
        ->and($sale->total)->toBe(1150.0)
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->notes)->toBe("Cotizacion {$quote->folio}");

    $quote->refresh();

    expect($quote->status)->toBe(Quote::CONVERTED)
        ->and($quote->sale_id)->toBe($sale->id)
        ->and($quote->converted_at)->not->toBeNull();
});

it('descuenta el inventario al convertir, no al cotizar', function () {
    $quote = makeQuote();
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(100.0);

    $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 1150],
    ]);

    expect(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(90.0);
});

it('no aplica promociones encima del precio ya cotizado', function () {
    $quote = makeQuote();

    // La promocion nace despues de haber cotizado.
    Promotion::create([
        'name' => '2x1 de aniversario',
        'type' => 'nxm',
        'applies_to_all' => true,
        'buy_quantity' => 2,
        'get_quantity' => 1,
    ]);

    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    $sale = $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 1150],
    ]);

    // Sin la proteccion, el 2x1 regalaria la mitad y el total caeria.
    expect($sale->discount)->toBe(0.0)
        ->and($sale->total)->toBe(1150.0);
});

it('no convierte dos veces la misma cotizacion', function () {
    $quote = makeQuote();
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);
    $payments = [['payment_method_id' => $this->cashMethod->id, 'amount' => 1150]];

    $this->quotes->convert($quote, $shift, $payments);

    expect(fn () => $this->quotes->convert($quote->fresh(), $shift, $payments))
        ->toThrow(RuntimeException::class, 'ya se convirtio');
});

it('no convierte una cotizacion rechazada', function () {
    $quote = $this->quotes->reject(makeQuote(), 'No la quiso');
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    expect(fn () => $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 1150],
    ]))->toThrow(RuntimeException::class, 'rechazada');
});

it('no convierte una cotizacion vencida', function () {
    $quote = makeQuote(['validUntil' => now()->subWeek()->toDateString()]);
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    expect(fn () => $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 1150],
    ]))->toThrow(RuntimeException::class, 'vencio');
});

it('no deja rastro de venta si la conversion falla', function () {
    // Se cobra de menos y sin cliente a credito: la venta debe rebotar.
    $quote = makeQuote();
    $shift = $this->cash->open($this->terminalId, $this->branchId, 0);

    expect(fn () => $this->quotes->convert($quote, $shift, [
        ['payment_method_id' => $this->cashMethod->id, 'amount' => 10],
    ]))->toThrow(RuntimeException::class);

    expect(Sale::count())->toBe(0)
        ->and($quote->fresh()->status)->toBe(Quote::PENDING)
        ->and(Inventory::where('product_id', $this->product->id)->value('quantity'))->toBe(100.0);
});

// =============================================================
// Aislamiento entre empresas
// =============================================================

it('no deja ver las cotizaciones de otra empresa', function () {
    makeQuote();

    actingAsTenant('general', 'otro@negocio.test');

    expect(Quote::count())->toBe(0);
});
