<?php

namespace App\Livewire\Pos;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\CashRegister;
use App\Services\Pricing;
use App\Services\SaleRegistrar;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;
use Throwable;

/**
 * Pantalla de venta.
 *
 * Esta hecha para trabajar con el escaner: se escribe o se escanea en la
 * caja de busqueda y el producto entra al carrito sin tocar el raton. Todo
 * lo demas (cliente, descuentos, forma de pago) es opcional.
 */
#[Layout('layouts.app')]
class Register extends Page
{
    /** Lo escrito o escaneado en la barra de busqueda. */
    public string $search = '';

    /**
     * Carrito. Cada linea guarda lo necesario para pintarla y para
     * registrarla, sin volver a consultar la base en cada tecla.
     */
    public array $cart = [];

    public ?string $customerId = null;

    public ?string $priceListId = null;

    // --- Cobro ---
    public bool $showPayment = false;

    /** [payment_method_id => monto] */
    public array $payments = [];

    public string $notes = '';

    // --- Apertura de caja ---
    public bool $showOpenShift = false;

    public ?float $openingAmount = null;

    // --- Ventas en espera ---
    public bool $showHeld = false;

    public string $holdLabel = '';

    /** Venta recien cobrada, para ofrecer el ticket. */
    public ?string $lastSaleId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('sales.create'), 403);

        $this->priceListId = PriceList::active()->where('is_default', true)->value('id');
    }

    // =========================================================
    // Estado de la caja
    // =========================================================

    #[Computed]
    public function terminalId(): ?string
    {
        return Terminal::where('branch_id', auth()->user()->branch_id)
            ->where('status', 'active')
            ->value('id')
            ?? Terminal::where('status', 'active')->value('id');
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return $this->terminalId ? Shift::openFor($this->terminalId) : null;
    }

    public function openShift(CashRegister $cash): void
    {
        abort_unless(auth()->user()->can('cash.open'), 403);

        $this->validate(
            ['openingAmount' => ['required', 'numeric', 'min:0']],
            ['openingAmount.required' => 'Indica con cuanto efectivo abres la caja.'],
        );

        try {
            $cash->open(
                $this->terminalId,
                auth()->user()->branch_id ?? Branch::active()->value('id'),
                (float) $this->openingAmount,
            );
        } catch (RuntimeException $e) {
            $this->addError('openingAmount', $e->getMessage());

            return;
        }

        unset($this->shift);
        $this->showOpenShift = false;
        $this->openingAmount = null;
        $this->notify('Caja abierta');
    }

    // =========================================================
    // Busqueda y alta de lineas
    // =========================================================

    #[Computed]
    public function results()
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->with(['baseUnit', 'units.unit', 'tax'])
            ->active()
            ->search($term)
            ->limit(12)
            ->get();
    }

    /**
     * Se dispara al presionar Enter en la barra de busqueda.
     *
     * Si lo escrito coincide exactamente con un codigo de barra, ese
     * producto entra directo: es el caso del escaner, que termina con
     * Enter. Si no, y hay un unico resultado, tambien entra.
     */
    public function submitSearch(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $barcode = ProductBarcode::where('code', $term)->first();

        if ($barcode !== null) {
            $this->addProduct($barcode->product_id, $barcode->product_unit_id, $barcode->variant_id);

            return;
        }

        $results = $this->results;

        if ($results->count() === 1) {
            $this->addProduct($results->first()->id);

            return;
        }

        if ($results->isEmpty()) {
            $this->notify('Ningun producto coincide con esa busqueda', 'error');
        }
    }

    public function addProduct(string $productId, ?string $productUnitId = null, ?string $variantId = null): void
    {
        $product = Product::with(['baseUnit', 'units.unit', 'tax', 'prices'])->find($productId);

        if ($product === null) {
            $this->notify('Ese producto ya no existe', 'error');

            return;
        }

        $unit = $productUnitId
            ? $product->units->firstWhere('id', $productUnitId)
            : $product->defaultUnit();

        $key = $productId.'|'.($variantId ?? '').'|'.($unit?->id ?? '');

        // Escanear dos veces el mismo producto suma cantidad en vez de
        // abrir otra linea: es lo que espera cualquier cajero.
        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity'] = Pricing::round($this->cart[$key]['quantity'] + 1, 3);
            $this->recalculateLine($key);
            $this->search = '';

            return;
        }

        $price = $this->priceFor($product, $unit?->id, (float) ($unit?->factor ?? 1), 1);

        if ($price === null) {
            $this->notify("\"{$product->name}\" no tiene precio en esta lista", 'error');

            return;
        }

        $this->cart[$key] = [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'product_unit_id' => $unit?->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_label' => $unit?->unit?->name ?? $product->baseUnit?->name,
            'unit_factor' => (float) ($unit?->factor ?? 1),
            'allows_decimals' => (bool) ($unit?->unit?->allows_decimals ?? $product->baseUnit?->allows_decimals),
            'tax_rate' => $product->taxRate(),
            'quantity' => 1.0,
            'unit_price' => $price,
            'discount' => 0.0,
            'total' => $price,
        ];

        $this->search = '';
    }

    /**
     * Precio que aplica, segun la lista activa y la cantidad.
     * La cantidad importa porque una lista puede tener precios por volumen.
     */
    protected function priceFor(Product $product, ?string $productUnitId, float $factor, float $quantity): ?float
    {
        $candidates = $product->prices
            ->whereNull('variant_id')
            ->map(fn ($price) => $price->toCandidate())
            ->values()
            ->all();

        return Pricing::resolve(
            candidates: $candidates,
            priceListId: (string) $this->priceListId,
            productUnitId: $productUnitId,
            quantity: $quantity,
            unitFactor: $factor,
            decimals: auth()->user()->tenant->price_decimals,
        );
    }

    // =========================================================
    // Carrito
    // =========================================================

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->cart[$key]['quantity'] = Pricing::round($this->cart[$key]['quantity'] + 1, 3);
        $this->recalculateLine($key);
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $next = Pricing::round($this->cart[$key]['quantity'] - 1, 3);

        if ($next <= 0) {
            $this->removeLine($key);

            return;
        }

        $this->cart[$key]['quantity'] = $next;
        $this->recalculateLine($key);
    }

    public function removeLine(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->customerId = null;
        $this->notes = '';
    }

    /** Al cambiar la cantidad a mano se revisa el precio por volumen. */
    public function updatedCart(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$lineKey, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (in_array($field, ['quantity', 'discount'], true) && isset($this->cart[$lineKey])) {
            $this->recalculateLine($lineKey);
        }
    }

    /**
     * Recalcula precio y total de una linea.
     *
     * El precio se vuelve a resolver porque la cantidad pudo cruzar un
     * tramo de precio por volumen. Un precio editado a mano no se toca.
     */
    protected function recalculateLine(string $key): void
    {
        $line = $this->cart[$key];
        $quantity = max(0.001, (float) $line['quantity']);

        if (! ($line['price_edited'] ?? false)) {
            $product = Product::with('prices')->find($line['product_id']);

            if ($product !== null) {
                $price = $this->priceFor(
                    $product,
                    $line['product_unit_id'],
                    (float) $line['unit_factor'],
                    $quantity,
                );

                if ($price !== null) {
                    $this->cart[$key]['unit_price'] = $price;
                }
            }
        }

        $totals = Pricing::line(
            quantity: $quantity,
            unitPrice: (float) $this->cart[$key]['unit_price'],
            taxRate: (float) $line['tax_rate'],
            discount: (float) ($line['discount'] ?? 0),
            mode: auth()->user()->tenant->taxMode(),
            decimals: auth()->user()->tenant->price_decimals,
        );

        $this->cart[$key]['quantity'] = $quantity;
        $this->cart[$key]['discount'] = $totals['discount'];
        $this->cart[$key]['total'] = $totals['gross'];
    }

    /** Cambiar de cliente puede cambiar la lista de precios. */
    public function updatedCustomerId(?string $value): void
    {
        $customer = $value ? Customer::with('customerType')->find($value) : null;

        $this->priceListId = $customer?->effectivePriceListId()
            ?? PriceList::active()->where('is_default', true)->value('id');

        foreach (array_keys($this->cart) as $key) {
            $this->recalculateLine($key);
        }
    }

    // =========================================================
    // Totales
    // =========================================================

    #[Computed]
    public function totals(): array
    {
        $decimals = auth()->user()->tenant->price_decimals;
        $mode = auth()->user()->tenant->taxMode();

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($this->cart as $line) {
            $lineTotals = Pricing::line(
                quantity: (float) $line['quantity'],
                unitPrice: (float) $line['unit_price'],
                taxRate: (float) $line['tax_rate'],
                discount: (float) ($line['discount'] ?? 0),
                mode: $mode,
                decimals: $decimals,
            );

            $subtotal += $lineTotals['subtotal'];
            $discount += $lineTotals['discount'];
            $tax += $lineTotals['tax'];
            $total += $lineTotals['gross'];
        }

        return [
            'items' => count($this->cart),
            'units' => Pricing::round(array_sum(array_column($this->cart, 'quantity')), 3),
            'subtotal' => Pricing::round($subtotal, $decimals),
            'discount' => Pricing::round($discount, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
        ];
    }

    #[Computed]
    public function paidAmount(): float
    {
        return Pricing::round(array_sum(array_map('floatval', $this->payments)), 2);
    }

    #[Computed]
    public function changeAmount(): float
    {
        return Pricing::round(max(0, $this->paidAmount - $this->totals['total']), 2);
    }

    // =========================================================
    // Cobro
    // =========================================================

    public function openPayment(): void
    {
        if ($this->cart === []) {
            $this->notify('Agrega productos antes de cobrar', 'error');

            return;
        }

        if ($this->shift === null) {
            $this->showOpenShift = true;

            return;
        }

        // El efectivo arranca con el total exacto: es el caso mas comun y
        // ahorra teclear en la mayoria de las ventas.
        $cash = $this->paymentMethods->firstWhere('type', 'cash');

        $this->payments = $cash
            ? [$cash->id => $this->totals['total']]
            : [];

        $this->resetValidation();
        $this->showPayment = true;
    }

    /** Deja un solo metodo con el total exacto. */
    public function payExact(string $methodId): void
    {
        $this->payments = [$methodId => $this->totals['total']];
    }

    public function addPaymentMethod(string $methodId): void
    {
        if (isset($this->payments[$methodId])) {
            return;
        }

        $pending = Pricing::round(max(0, $this->totals['total'] - $this->paidAmount), 2);
        $this->payments[$methodId] = $pending;
    }

    public function removePaymentMethod(string $methodId): void
    {
        unset($this->payments[$methodId]);
    }

    public function checkout(SaleRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('sales.create'), 403);

        if ($this->shift === null) {
            $this->addError('payments', 'No hay un turno de caja abierto.');

            return;
        }

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'product_unit_id' => $line['product_unit_id'],
            'quantity' => (float) $line['quantity'],
            'unit_price' => (float) $line['unit_price'],
            'discount' => (float) ($line['discount'] ?? 0),
        ], array_values($this->cart));

        $payments = [];

        foreach ($this->payments as $methodId => $amount) {
            if ((float) $amount > 0) {
                $payments[] = ['payment_method_id' => $methodId, 'amount' => (float) $amount];
            }
        }

        try {
            $sale = $registrar->register(
                shift: $this->shift,
                lines: $lines,
                payments: $payments,
                customerId: $this->customerId,
                priceListId: $this->priceListId,
                notes: $this->notes ?: null,
            );
        } catch (RuntimeException $e) {
            // Los errores de negocio se muestran tal cual: estan escritos
            // para que el cajero sepa que hacer.
            $this->addError('payments', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('payments', 'No se pudo registrar la venta. Intenta de nuevo.');

            return;
        }

        $this->lastSaleId = $sale->id;
        $this->showPayment = false;
        $this->payments = [];
        $this->clearCart();
        unset($this->shift);

        $this->notify("Venta {$sale->folio} registrada");
    }

    // =========================================================
    // Ventas en espera
    // =========================================================

    public function hold(): void
    {
        if ($this->cart === []) {
            $this->notify('No hay nada que dejar en espera', 'error');

            return;
        }

        HeldSale::create([
            'branch_id' => auth()->user()->branch_id ?? Branch::active()->value('id'),
            'terminal_id' => $this->terminalId,
            'user_id' => auth()->id(),
            'customer_id' => $this->customerId,
            'label' => $this->holdLabel ?: 'Cuenta '.now()->format('H:i'),
            'cart' => $this->cart,
            'total' => $this->totals['total'],
        ]);

        $this->clearCart();
        $this->holdLabel = '';
        $this->notify('Venta guardada en espera');
    }

    public function resume(string $heldId): void
    {
        $held = HeldSale::find($heldId);

        if ($held === null) {
            return;
        }

        if ($this->cart !== []) {
            $this->notify('Termina o guarda la venta actual primero', 'error');

            return;
        }

        $this->cart = $held->cart;
        $this->customerId = $held->customer_id;
        $held->delete();

        $this->showHeld = false;
        $this->notify('Venta recuperada');
    }

    public function discardHeld(string $heldId): void
    {
        HeldSale::whereKey($heldId)->delete();
        $this->notify('Venta en espera descartada');
    }

    // =========================================================
    // Datos de apoyo
    // =========================================================

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::active()->orderBy('position')->get();
    }

    #[Computed]
    public function customers()
    {
        return Customer::active()->orderBy('name')->limit(200)->get();
    }

    #[Computed]
    public function heldSales()
    {
        return HeldSale::with('customer')
            ->where('terminal_id', $this->terminalId)
            ->latest()
            ->get();
    }

    #[Computed]
    public function lastSale(): ?Sale
    {
        return $this->lastSaleId ? Sale::find($this->lastSaleId) : null;
    }

    public function render()
    {
        return view('livewire.pos.register', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'priceLists' => PriceList::active()->orderBy('position')->get(),
        ]);
    }
}
