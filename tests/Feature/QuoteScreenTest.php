<?php

use App\Livewire\Quotes\Form;
use App\Livewire\Quotes\Index;
use App\Livewire\Quotes\Show;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\QuoteRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branch = $this->context['setup']['branch'];
    $this->terminal = $this->context['setup']['terminal'];

    $this->arroz = Product::create([
        'sku' => 'ABA-1',
        'name' => 'Arroz 5kg',
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'tax_id' => Tax::where('is_default', true)->value('id'),
        'cost' => 60,
    ]);

    ProductPrice::create([
        'product_id' => $this->arroz->id,
        'price_list_id' => PriceList::where('is_default', true)->value('id'),
        'min_quantity' => 1,
        'price' => 115,
    ]);

    app(InventoryManager::class)->move($this->arroz, $this->branch->id, 100, 'initial');
});

function screenQuote(array $overrides = []): Quote
{
    return app(QuoteRegistrar::class)->create(
        branchId: test()->branch->id,
        lines: $overrides['lines'] ?? [[
            'product_id' => test()->arroz->id,
            'quantity' => 10,
            'unit_price' => 115,
        ]],
        customerName: $overrides['customerName'] ?? 'Bodega La Esquina',
        customerPhone: '999888777',
        validUntil: $overrides['validUntil'] ?? null,
    );
}

// =============================================================
// Acceso
// =============================================================

describe('acceso', function () {
    it('niega el listado a quien no puede ver cotizaciones', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'quotes.view' => false],
        ]);

        $this->get(route('quotes'))->assertForbidden();
    });

    it('deja mirar pero no crear a quien solo puede ver', function () {
        screenQuote();

        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'quotes.view' => true],
        ]);

        $this->get(route('quotes'))->assertOk()->assertDontSee('+ Cotizacion');

        Livewire::test(Form::class)->assertForbidden();
    });

    it('deja mirar el detalle pero no responder a quien solo puede ver', function () {
        $quote = screenQuote();

        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'quotes.view' => true],
        ]);

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->call('approve')
            ->assertForbidden();
    });
});

// =============================================================
// Listado
// =============================================================

describe('listado', function () {
    it('muestra las cotizaciones con su folio y total', function () {
        $quote = screenQuote();

        Livewire::test(Index::class)
            ->assertSee($quote->folio)
            ->assertSee('Bodega La Esquina')
            ->assertSee('1,150.00');
    });

    it('filtra por cliente', function () {
        screenQuote(['customerName' => 'Bodega La Esquina']);
        screenQuote(['customerName' => 'Abarrotes Don Beto']);

        Livewire::test(Index::class)
            ->set('search', 'Don Beto')
            ->assertSee('Abarrotes Don Beto')
            ->assertDontSee('Bodega La Esquina');
    });

    it('filtra las vencidas por fecha, no por estado guardado', function () {
        $vigente = screenQuote();
        $vencida = screenQuote(['validUntil' => now()->subWeek()->toDateString()]);

        // Las dos siguen en estado "pending" en la base.
        expect($vencida->status)->toBe(Quote::PENDING);

        Livewire::test(Index::class)
            ->set('status', 'expired')
            ->assertSee($vencida->folio)
            ->assertDontSee($vigente->folio);
    });

    it('avisa cuantas se pasaron de fecha', function () {
        screenQuote(['validUntil' => now()->subDay()->toDateString()]);

        Livewire::test(Index::class)->assertSee('se paso');
    });
});

// =============================================================
// Alta
// =============================================================

describe('alta', function () {
    it('propone el precio de la lista al agregar un producto', function () {
        Livewire::test(Form::class)
            ->call('addProduct', $this->arroz->id)
            ->assertSet('lines.'.$this->arroz->id.'|.unit_price', 115.0);
    });

    it('crea la cotizacion y redirige a su detalle', function () {
        Livewire::test(Form::class)
            ->call('addProduct', $this->arroz->id)
            ->set('lines.'.$this->arroz->id.'|.quantity', 10)
            ->set('customerName', 'Bodega La Esquina')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $quote = Quote::sole();

        expect($quote->total)->toBe(1150.0)
            ->and($quote->customer_name)->toBe('Bodega La Esquina')
            ->and($quote->status)->toBe(Quote::PENDING);
    });

    it('exige a nombre de quien va', function () {
        Livewire::test(Form::class)
            ->call('addProduct', $this->arroz->id)
            ->set('customerName', '')
            ->call('save')
            ->assertHasErrors('customerName');
    });

    it('no guarda una cotizacion sin productos', function () {
        Livewire::test(Form::class)
            ->set('customerName', 'Alguien')
            ->call('save')
            ->assertHasErrors('lines');
    });

    it('copia el nombre y el telefono al elegir un cliente registrado', function () {
        $customer = Customer::create([
            'name' => 'Distribuidora del Sur',
            'phone' => '555111222',
        ]);

        Livewire::test(Form::class)
            ->set('customerId', $customer->id)
            ->assertSet('customerName', 'Distribuidora del Sur')
            ->assertSet('customerPhone', '555111222');
    });

    it('no descuenta inventario al cotizar', function () {
        Livewire::test(Form::class)
            ->call('addProduct', $this->arroz->id)
            ->set('customerName', 'Alguien')
            ->call('save')
            ->assertHasNoErrors();

        expect(Inventory::where('product_id', $this->arroz->id)->value('quantity'))->toBe(100.0);
    });
});

// =============================================================
// Edicion
// =============================================================

describe('edicion', function () {
    it('carga las lineas de la cotizacion que se edita', function () {
        $quote = screenQuote();

        Livewire::test(Form::class, ['quoteId' => $quote->id])
            ->assertSet('customerName', 'Bodega La Esquina')
            ->assertSet('lines.'.$this->arroz->id.'|.quantity', 10.0);
    });

    it('no deja abrir en edicion una ya respondida', function () {
        $quote = app(QuoteRegistrar::class)->approve(screenQuote());

        Livewire::test(Form::class, ['quoteId' => $quote->id])->assertForbidden();
    });
});

// =============================================================
// Detalle y acciones
// =============================================================

describe('detalle', function () {
    it('muestra las lineas y el total', function () {
        $quote = screenQuote();

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->assertSee('Arroz 5kg')
            ->assertSee('1,150.00')
            ->assertSee('Pendiente');
    });

    it('aprueba desde la pantalla', function () {
        $quote = screenQuote();

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->call('approve')
            ->assertSee('Aprobada');

        expect($quote->fresh()->status)->toBe(Quote::APPROVED);
    });

    it('exige un motivo para rechazar', function () {
        $quote = screenQuote();

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->set('rejectReason', '')
            ->call('reject')
            ->assertHasErrors('rejectReason');
    });

    it('rechaza guardando el motivo', function () {
        $quote = screenQuote();

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->set('rejectReason', 'Encontro mas barato')
            ->call('reject')
            ->assertSee('Encontro mas barato');

        expect($quote->fresh()->status)->toBe(Quote::REJECTED);
    });

    it('avisa que esta vencida en lugar de dejar convertirla', function () {
        $quote = screenQuote(['validUntil' => now()->subWeek()->toDateString()]);

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->assertSee('Vencida')
            ->assertSee('Extender vigencia');
    });

    it('extiende la vigencia', function () {
        $quote = screenQuote(['validUntil' => now()->subWeek()->toDateString()]);

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->set('newValidUntil', now()->addDays(5)->toDateString())
            ->call('extend')
            ->assertHasNoErrors();

        expect($quote->fresh()->isExpired())->toBeFalse();
    });

    it('no acepta extender a una fecha ya pasada', function () {
        $quote = screenQuote(['validUntil' => now()->subWeek()->toDateString()]);

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->set('newValidUntil', now()->subDay()->toDateString())
            ->call('extend')
            ->assertHasErrors('newValidUntil');
    });
});

// =============================================================
// Conversion en venta
// =============================================================

describe('conversion', function () {
    it('pide abrir la caja antes de convertir', function () {
        $quote = screenQuote();

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->call('convert')
            ->assertHasErrors('paymentMethodId');

        expect(Sale::count())->toBe(0);
    });

    it('genera la venta y redirige a ella', function () {
        $quote = screenQuote();
        app(CashRegister::class)->open($this->terminal->id, $this->branch->id, 0);

        Livewire::test(Show::class, ['quoteId' => $quote->id])
            ->set('paymentMethodId', PaymentMethod::where('code', 'EFE')->value('id'))
            ->call('convert')
            ->assertHasNoErrors()
            ->assertRedirect();

        $sale = Sale::sole();

        expect($sale->total)->toBe(1150.0)
            ->and($quote->fresh()->sale_id)->toBe($sale->id);

        expect(Inventory::where('product_id', $this->arroz->id)->value('quantity'))->toBe(90.0);
    });

    it('no ofrece convertir una ya convertida', function () {
        $quote = screenQuote();
        $shift = app(CashRegister::class)->open($this->terminal->id, $this->branch->id, 0);

        app(QuoteRegistrar::class)->convert($quote, $shift, [[
            'payment_method_id' => PaymentMethod::where('code', 'EFE')->value('id'),
            'amount' => 1150,
        ]]);

        Livewire::test(Show::class, ['quoteId' => $quote->fresh()->id])
            ->assertSee('Se convirtio en la venta')
            ->assertDontSee('Convertir en venta');
    });
});
