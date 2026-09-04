<?php

namespace App\Livewire\Quotes;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Quote;
use App\Services\Pricing;
use App\Services\QuoteRegistrar;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;
use Throwable;

/**
 * Alta y edicion de una cotizacion.
 *
 * Se parece a la pantalla de venta, pero sin caja ni pagos: aqui no se
 * cobra nada. Lo que se decide es a que precio se compromete el negocio
 * y hasta cuando.
 */
#[Layout('layouts.app')]
class Form extends Page
{
    public ?Quote $quote = null;

    public string $search = '';

    /** Lineas de la cotizacion, indexadas por producto y presentacion. */
    public array $lines = [];

    public string $branchId = '';

    public ?string $customerId = null;

    public string $customerName = '';

    public string $customerPhone = '';

    public ?string $priceListId = null;

    public string $validUntil = '';

    public string $notes = '';

    public function mount(?string $quoteId = null): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        $this->branchId = (string) (auth()->user()->branch_id ?? Branch::active()->value('id'));
        $this->priceListId = PriceList::active()->where('is_default', true)->value('id');
        $this->validUntil = now()->addDays(15)->toDateString();

        if ($quoteId !== null) {
            $this->loadExisting($quoteId);
        }
    }

    protected function loadExisting(string $quoteId): void
    {
        $quote = Quote::with('items')->findOrFail($quoteId);

        // Editar una que ya fue respondida reescribiria el precio que el
        // cliente tiene en la mano.
        abort_unless($quote->isEditable(), 403);

        $this->quote = $quote;
        $this->branchId = $quote->branch_id;
        $this->customerId = $quote->customer_id;
        $this->customerName = $quote->customer_name;
        $this->customerPhone = (string) $quote->customer_phone;
        $this->priceListId = $quote->price_list_id ?? $this->priceListId;
        $this->validUntil = $quote->valid_until->toDateString();
        $this->notes = (string) $quote->notes;

        foreach ($quote->items as $item) {
            $this->lines[$this->lineKey($item->product_id, $item->product_unit_id)] = [
                'product_id' => $item->product_id,
                'product_unit_id' => $item->product_unit_id,
                'name' => $item->description,
                'sku' => $item->sku,
                'unit_label' => $item->unit_label,
                'unit_factor' => $item->unit_factor,
                'tax_rate' => $item->tax_rate,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => $item->discount,
                'total' => $item->total,
            ];
        }
    }

    protected function lineKey(string $productId, ?string $productUnitId): string
    {
        return $productId.'|'.($productUnitId ?? '');
    }

    // =========================================================
    // Cliente
    // =========================================================

    /**
     * Al elegir un cliente registrado se copia su nombre y su telefono, y
     * se cambia a su lista de precios: cotizarle a precio de mostrador a
     * quien tiene precio de mayoreo es perder la venta.
     */
    public function updatedCustomerId(?string $value): void
    {
        if (! $value) {
            return;
        }

        $customer = Customer::find($value);

        if ($customer === null) {
            return;
        }

        $this->customerName = $customer->name;
        $this->customerPhone = (string) ($customer->phone ?? $this->customerPhone);
        $this->priceListId = $customer->effectivePriceListId() ?? $this->priceListId;

        $this->repriceLines();
    }

    // =========================================================
    // Busqueda de productos
    // =========================================================

    #[Computed]
    public function results()
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->with(['baseUnit', 'units.unit', 'tax', 'prices'])
            ->active()
            ->search($term)
            ->limit(8)
            ->get();
    }

    public function submitSearch(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $barcode = ProductBarcode::where('code', $term)->first();

        if ($barcode !== null) {
            $this->addProduct($barcode->product_id, $barcode->product_unit_id);

            return;
        }

        if ($this->results->count() === 1) {
            $this->addProduct($this->results->first()->id);

            return;
        }

        if ($this->results->isEmpty()) {
            $this->notify('Ningun producto coincide con esa busqueda', 'error');
        }
    }

    public function addProduct(string $productId, ?string $productUnitId = null): void
    {
        $product = Product::with(['baseUnit', 'units.unit', 'tax', 'prices'])->find($productId);

        if ($product === null) {
            return;
        }

        $unit = $productUnitId
            ? $product->units->firstWhere('id', $productUnitId)
            : $product->defaultUnit();

        $key = $this->lineKey($productId, $unit?->id);

        if (isset($this->lines[$key])) {
            $this->lines[$key]['quantity'] = Pricing::round($this->lines[$key]['quantity'] + 1, 3);
            $this->repriceLine($key);
            $this->search = '';

            return;
        }

        $factor = (float) ($unit?->factor ?? 1);

        $this->lines[$key] = [
            'product_id' => $product->id,
            'product_unit_id' => $unit?->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_label' => $unit?->unit?->name ?? $product->baseUnit?->name,
            'unit_factor' => $factor,
            'tax_rate' => $product->taxRate(),
            'quantity' => 1.0,
            'unit_price' => $this->priceFor($product, $unit?->id, $factor, 1.0) ?? 0.0,
            'discount' => 0.0,
            'total' => 0.0,
        ];

        $this->recalculateLine($key);
        $this->search = '';
    }

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
    // Lineas
    // =========================================================

    public function removeLine(string $key): void
    {
        unset($this->lines[$key]);
    }

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$lineKey, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (! isset($this->lines[$lineKey])) {
            return;
        }

        // Cambiar la cantidad puede saltar de tramo de precio por volumen,
        // asi que se vuelve a resolver el precio; tocar el precio a mano
        // no, o se pisaria lo que la persona acaba de escribir.
        if ($field === 'quantity') {
            $this->repriceLine($lineKey);

            return;
        }

        if (in_array($field, ['unit_price', 'discount'], true)) {
            $this->recalculateLine($lineKey);
        }
    }

    /** Vuelve a proponer el precio de una linea segun su cantidad. */
    protected function repriceLine(string $key): void
    {
        $line = $this->lines[$key];
        $product = Product::with('prices')->find($line['product_id']);

        if ($product !== null) {
            $price = $this->priceFor(
                $product,
                $line['product_unit_id'],
                (float) $line['unit_factor'],
                max(0.001, (float) $line['quantity']),
            );

            if ($price !== null) {
                $this->lines[$key]['unit_price'] = $price;
            }
        }

        $this->recalculateLine($key);
    }

    protected function repriceLines(): void
    {
        foreach (array_keys($this->lines) as $key) {
            $this->repriceLine($key);
        }
    }

    protected function recalculateLine(string $key): void
    {
        $line = $this->lines[$key];

        $totals = Pricing::line(
            quantity: max(0.001, (float) $line['quantity']),
            unitPrice: (float) $line['unit_price'],
            taxRate: (float) $line['tax_rate'],
            discount: (float) ($line['discount'] ?? 0),
            mode: auth()->user()->tenant->taxMode(),
            decimals: auth()->user()->tenant->price_decimals,
        );

        $this->lines[$key]['total'] = $totals['gross'];
    }

    // =========================================================
    // Totales
    // =========================================================

    #[Computed]
    public function totals(): array
    {
        $decimals = auth()->user()->tenant->price_decimals;
        $mode = auth()->user()->tenant->taxMode();
        $subtotal = $discount = $tax = $total = 0;

        foreach ($this->lines as $line) {
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
            'items' => count($this->lines),
            'subtotal' => Pricing::round($subtotal, $decimals),
            'discount' => Pricing::round($discount, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
        ];
    }

    // =========================================================
    // Guardar
    // =========================================================

    public function save(QuoteRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('quotes.manage'), 403);

        $this->validate([
            'branchId' => ['required', 'exists:branches,id'],
            'customerId' => ['nullable', 'exists:customers,id'],
            'customerName' => ['required', 'string', 'max:150'],
            'customerPhone' => ['nullable', 'string', 'max:40'],
            'validUntil' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'customerName.required' => 'Escribe a nombre de quien va la cotizacion.',
            'validUntil.required' => 'Indica hasta cuando se sostiene el precio.',
        ]);

        if ($this->lines === []) {
            $this->addError('lines', 'Agrega al menos un producto.');

            return;
        }

        $payload = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'product_unit_id' => $line['product_unit_id'],
            'quantity' => (float) $line['quantity'],
            'unit_price' => (float) $line['unit_price'],
            'discount' => (float) ($line['discount'] ?? 0),
        ], array_values($this->lines));

        try {
            $quote = $this->quote === null
                ? $registrar->create(
                    branchId: $this->branchId,
                    lines: $payload,
                    customerName: $this->customerName,
                    customerId: $this->customerId,
                    customerPhone: $this->customerPhone ?: null,
                    priceListId: $this->priceListId,
                    validUntil: $this->validUntil,
                    notes: $this->notes ?: null,
                )
                : $registrar->update(
                    quote: $this->quote,
                    lines: $payload,
                    customerName: $this->customerName,
                    customerId: $this->customerId,
                    customerPhone: $this->customerPhone ?: null,
                    priceListId: $this->priceListId,
                    validUntil: $this->validUntil,
                    notes: $this->notes ?: null,
                );
        } catch (RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('lines', 'No se pudo guardar la cotizacion. Intenta de nuevo.');

            return;
        }

        $this->notify("Cotizacion {$quote->folio} guardada");
        $this->redirectRoute('quotes.show', ['quoteId' => $quote->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.quotes.form', [
            'customers' => Customer::active()->orderBy('name')->get(),
            'branches' => Branch::active()->orderBy('name')->get(),
            'priceLists' => PriceList::active()->orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
