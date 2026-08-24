<?php

namespace App\Livewire\Purchases;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Supplier;
use App\Services\Pricing;
use App\Services\PurchaseRegistrar;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;
use Throwable;

/**
 * Registro de una compra.
 *
 * Funciona como la pantalla de venta pero al reves: se busca o escanea el
 * producto y se captura a que costo entro. Al guardar, la mercancia entra
 * al inventario y el costo del producto se actualiza.
 */
#[Layout('layouts.app')]
class Form extends Page
{
    public string $search = '';

    /** Lineas de la compra, indexadas por producto y presentacion. */
    public array $lines = [];

    public ?string $supplierId = null;

    public string $branchId = '';

    public string $paymentType = 'cash';

    public ?string $paymentMethodId = null;

    public string $invoiceNumber = '';

    public string $dueDate = '';

    public bool $updatesCost = true;

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('purchases.create'), 403);

        $this->branchId = auth()->user()->branch_id ?? (string) Branch::active()->value('id');
        $this->paymentMethodId = PaymentMethod::active()->where('type', 'cash')->value('id');
        $this->dueDate = now()->addDays(30)->toDateString();
    }

    // =========================================================
    // Busqueda
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
            ->limit(10)
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
        $product = Product::with(['baseUnit', 'units.unit', 'tax'])->find($productId);

        if ($product === null) {
            return;
        }

        // Al comprar se prefiere la presentacion marcada como de compra:
        // normalmente se compra por caja aunque se venda por pieza.
        $unit = $productUnitId
            ? $product->units->firstWhere('id', $productUnitId)
            : ($product->units->firstWhere('is_purchase', true) ?? $product->defaultUnit());

        $key = $productId.'|'.($unit?->id ?? '');

        if (isset($this->lines[$key])) {
            $this->lines[$key]['quantity'] = Pricing::round($this->lines[$key]['quantity'] + 1, 3);
            $this->recalculateLine($key);
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
            'track_lots' => $product->track_lots,
            'track_expiry' => $product->track_expiry,
            'quantity' => 1.0,
            // Se propone el ultimo costo conocido, escalado a la
            // presentacion que se esta comprando.
            'unit_cost' => Pricing::round((float) $product->cost * $factor, 4),
            'discount' => 0.0,
            'lot_number' => '',
            'expiry_date' => '',
            'total' => 0.0,
        ];

        $this->recalculateLine($key);
        $this->search = '';
    }

    // =========================================================
    // Lineas
    // =========================================================

    public function removeLine(string $key): void
    {
        unset($this->lines[$key]);
    }

    public function clearLines(): void
    {
        $this->lines = [];
    }

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$lineKey, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (in_array($field, ['quantity', 'unit_cost', 'discount'], true) && isset($this->lines[$lineKey])) {
            $this->recalculateLine($lineKey);
        }
    }

    protected function recalculateLine(string $key): void
    {
        $line = $this->lines[$key];

        $totals = Pricing::line(
            quantity: max(0.001, (float) $line['quantity']),
            unitPrice: (float) $line['unit_cost'],
            taxRate: (float) $line['tax_rate'],
            discount: (float) ($line['discount'] ?? 0),
            // El costo de compra siempre se captura sin impuesto.
            mode: Pricing::TAX_ADDED,
            decimals: auth()->user()->tenant->price_decimals,
        );

        $this->lines[$key]['total'] = $totals['gross'];
    }

    /** Costo por unidad base, para que se vea a cuanto sale la pieza. */
    public function baseCostFor(array $line): float
    {
        $baseQuantity = (float) $line['quantity'] * (float) $line['unit_factor'];

        if ($baseQuantity <= 0) {
            return 0.0;
        }

        $net = Pricing::round(
            ((float) $line['quantity'] * (float) $line['unit_cost']) - (float) ($line['discount'] ?? 0),
            4,
        );

        return Pricing::round($net / $baseQuantity, 4);
    }

    // =========================================================
    // Totales
    // =========================================================

    #[Computed]
    public function totals(): array
    {
        $decimals = auth()->user()->tenant->price_decimals;
        $subtotal = $discount = $tax = $total = 0;

        foreach ($this->lines as $line) {
            $lineTotals = Pricing::line(
                quantity: (float) $line['quantity'],
                unitPrice: (float) $line['unit_cost'],
                taxRate: (float) $line['tax_rate'],
                discount: (float) ($line['discount'] ?? 0),
                mode: Pricing::TAX_ADDED,
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

    public function save(PurchaseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('purchases.create'), 403);

        $this->validate([
            'branchId' => ['required', 'exists:branches,id'],
            'paymentType' => ['required', 'in:cash,credit'],
            'supplierId' => [
                $this->paymentType === 'credit' ? 'required' : 'nullable',
                'nullable', 'exists:suppliers,id',
            ],
            'invoiceNumber' => ['nullable', 'string', 'max:60'],
            'dueDate' => [$this->paymentType === 'credit' ? 'required' : 'nullable', 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'supplierId.required' => 'Una compra a credito necesita proveedor.',
            'dueDate.required' => 'Indica cuando se paga.',
        ]);

        if ($this->lines === []) {
            $this->addError('lines', 'Agrega al menos un producto.');

            return;
        }

        $payload = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'product_unit_id' => $line['product_unit_id'],
            'quantity' => (float) $line['quantity'],
            'unit_cost' => (float) $line['unit_cost'],
            'discount' => (float) ($line['discount'] ?? 0),
            'lot_number' => $line['lot_number'] ?: null,
            'expiry_date' => $line['expiry_date'] ?: null,
        ], array_values($this->lines));

        try {
            $purchase = $registrar->register(
                branchId: $this->branchId,
                lines: $payload,
                supplierId: $this->supplierId,
                paymentType: $this->paymentType,
                invoiceNumber: $this->invoiceNumber ?: null,
                dueDate: $this->dueDate ?: null,
                updatesCost: $this->updatesCost,
                notes: $this->notes ?: null,
                paymentMethodId: $this->paymentMethodId,
            );
        } catch (RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('lines', 'No se pudo registrar la compra. Intenta de nuevo.');

            return;
        }

        $this->notify("Compra {$purchase->folio} registrada");
        $this->redirectRoute('purchases.show', ['purchaseId' => $purchase->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.purchases.form', [
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'branches' => Branch::active()->orderBy('name')->get(),
            'paymentMethods' => PaymentMethod::active()->where('type', '!=', 'credit')->orderBy('position')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
