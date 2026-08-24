<?php

namespace App\Services;

use App\Models\CostHistory;
use App\Models\DocumentSeries;
use App\Models\Inventory;
use App\Models\PriceHistory;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductPrice;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra una compra.
 *
 * Al recibirla entran tres cosas a la vez: la mercancia al inventario, el
 * costo nuevo al producto y el saldo al proveedor si fue a credito. Van
 * en una sola transaccion, porque una compra a medias dejaria existencia
 * sin costo o deuda sin mercancia.
 */
class PurchaseRegistrar
{
    public function __construct(protected InventoryManager $inventory) {}

    /**
     * @param  array<int, array{
     *     product_id: string, variant_id?: ?string, product_unit_id?: ?string,
     *     quantity: float, unit_cost: float, discount?: float,
     *     lot_number?: ?string, expiry_date?: ?string
     * }>  $lines
     */
    public function register(
        string $branchId,
        array $lines,
        ?string $supplierId = null,
        string $paymentType = 'cash',
        ?string $invoiceNumber = null,
        ?string $dueDate = null,
        bool $updatesCost = true,
        ?string $notes = null,
        ?string $paymentMethodId = null,
    ): Purchase {
        if ($lines === []) {
            throw new RuntimeException('La compra no tiene productos.');
        }

        if ($paymentType === 'credit' && $supplierId === null) {
            throw new RuntimeException('Una compra a credito necesita un proveedor.');
        }

        return DB::transaction(function () use (
            $branchId, $lines, $supplierId, $paymentType, $invoiceNumber,
            $dueDate, $updatesCost, $notes, $paymentMethodId
        ) {
            $tenant = auth()->user()->tenant;
            $decimals = $tenant->price_decimals;

            // El costo de compra se captura siempre sin impuesto: es lo
            // que se compara contra el precio de venta para el margen.
            $prepared = $this->prepareLines($lines, $decimals);
            $totals = $this->sumLines($prepared, $decimals);

            $purchase = Purchase::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'user_id' => auth()->id(),
                'folio' => $this->nextFolio($branchId),
                'invoice_number' => $invoiceNumber,
                'payment_type' => $paymentType,
                'due_date' => $paymentType === 'credit' ? $dueDate : null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                // Una compra de contado nace saldada.
                'paid' => $paymentType === 'cash' ? $totals['total'] : 0,
                'updates_cost' => $updatesCost,
                'status' => 'received',
                'notes' => $notes,
                'received_at' => now(),
            ]);

            foreach ($prepared as $position => $line) {
                $item = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line['product']->id,
                    'variant_id' => $line['variant_id'],
                    'product_unit_id' => $line['product_unit_id'],
                    'description' => $line['product']->name,
                    'sku' => $line['product']->sku,
                    'unit_label' => $line['unit_label'],
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_factor' => $line['unit_factor'],
                    'unit_cost' => $line['unit_cost'],
                    'base_unit_cost' => $line['base_unit_cost'],
                    'discount' => $line['totals']['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['totals']['tax'],
                    'net' => $line['totals']['net'],
                    'total' => $line['totals']['gross'],
                    'lot_number' => $line['lot_number'],
                    'expiry_date' => $line['expiry_date'],
                    'position' => $position,
                ]);

                $this->receiveStock($purchase, $line, $item);

                if ($updatesCost) {
                    $this->updateCost($line['product'], $line['base_unit_cost']);
                }
            }

            if ($paymentType === 'cash') {
                $this->recordCashPayment($purchase, $paymentMethodId);
            } else {
                $this->chargeSupplier($supplierId, $totals['total']);
            }

            return $purchase->load(['items', 'supplier']);
        });
    }

    // =========================================================
    // Lineas
    // =========================================================

    protected function prepareLines(array $lines, int $decimals): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $product = Product::with(['tax', 'units.unit'])->find($line['product_id']);

            if ($product === null) {
                throw new RuntimeException('Uno de los productos ya no existe.');
            }

            $productUnitId = $line['product_unit_id'] ?? null;
            $unit = $productUnitId
                ? $product->units->firstWhere('id', $productUnitId)
                : $product->defaultUnit();

            $factor = (float) ($unit?->factor ?? 1);
            $quantity = (float) $line['quantity'];
            $unitCost = (float) $line['unit_cost'];

            if ($quantity <= 0) {
                throw new RuntimeException("La cantidad de \"{$product->name}\" debe ser mayor que cero.");
            }

            if ($unitCost < 0) {
                throw new RuntimeException("El costo de \"{$product->name}\" no puede ser negativo.");
            }

            $lotNumber = $line['lot_number'] ?? null;
            $expiryDate = $line['expiry_date'] ?? null;

            if ($product->track_lots && ! $lotNumber) {
                throw new RuntimeException("\"{$product->name}\" necesita numero de lote.");
            }

            if ($product->track_expiry && ! $expiryDate) {
                throw new RuntimeException("\"{$product->name}\" necesita fecha de vencimiento.");
            }

            // El costo se captura sin impuesto, asi que el desglose usa
            // siempre el modo 'added' sin importar como venda la empresa.
            $totals = Pricing::line(
                quantity: $quantity,
                unitPrice: $unitCost,
                taxRate: $product->taxRate(),
                discount: (float) ($line['discount'] ?? 0),
                mode: Pricing::TAX_ADDED,
                decimals: $decimals,
            );

            $baseQuantity = Pricing::toBaseQuantity($quantity, $factor);

            $prepared[] = [
                'product' => $product,
                'variant_id' => $line['variant_id'] ?? null,
                'product_unit_id' => $unit?->id,
                'unit_label' => $unit?->unit?->name,
                'quantity' => $quantity,
                'unit_factor' => $factor,
                'base_quantity' => $baseQuantity,
                'unit_cost' => $unitCost,
                // Costo real por pieza: comprar una caja de 24 a 240
                // significa que la pieza costo 10, no 240.
                'base_unit_cost' => $baseQuantity > 0
                    ? Pricing::round($totals['net'] / $baseQuantity, 4)
                    : 0.0,
                'tax_rate' => $product->taxRate(),
                'lot_number' => $lotNumber,
                'expiry_date' => $expiryDate,
                'totals' => $totals,
            ];
        }

        return $prepared;
    }

    protected function sumLines(array $prepared, int $decimals): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($prepared as $line) {
            $subtotal += $line['totals']['subtotal'];
            $discount += $line['totals']['discount'];
            $tax += $line['totals']['tax'];
            $total += $line['totals']['gross'];
        }

        return [
            'subtotal' => Pricing::round($subtotal, $decimals),
            'discount' => Pricing::round($discount, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
        ];
    }

    /** Mete la mercancia al inventario, en su lote si lo maneja. */
    protected function receiveStock(Purchase $purchase, array $line, PurchaseItem $item): void
    {
        $product = $line['product'];

        if (! $product->track_stock) {
            return;
        }

        if ($product->track_lots) {
            $this->inventory->receiveLot(
                product: $product,
                branchId: $purchase->branch_id,
                lotNumber: $line['lot_number'],
                quantity: $line['base_quantity'],
                expiryDate: $line['expiry_date'],
                cost: $line['base_unit_cost'],
                variantId: $line['variant_id'],
                type: 'purchase',
                reason: "Compra {$purchase->folio}",
            );

            $item->update([
                'lot_id' => ProductLot::where('product_id', $product->id)
                    ->where('branch_id', $purchase->branch_id)
                    ->where('lot_number', $line['lot_number'])
                    ->value('id'),
            ]);

            return;
        }

        // El costo con el que entra manda sobre el del catalogo, para que
        // el promedio ponderado use lo que realmente se pago.
        $product->cost = $line['base_unit_cost'];

        $this->inventory->move(
            product: $product,
            branchId: $purchase->branch_id,
            quantity: $line['base_quantity'],
            type: 'purchase',
            reason: "Compra {$purchase->folio}",
            variantId: $line['variant_id'],
            referenceType: 'purchase',
            referenceId: $purchase->id,
        );
    }

    // =========================================================
    // Costos y precios
    // =========================================================

    /**
     * Deja el costo nuevo en el producto y lo registra.
     *
     * Despues recalcula los precios de las listas que trabajan por margen,
     * que es lo que hace que subir el costo suba el precio de venta sin
     * tener que tocar producto por producto.
     */
    protected function updateCost(Product $product, float $newCost): void
    {
        $oldCost = (float) $product->getOriginal('cost');

        if (Pricing::round($oldCost, 4) === Pricing::round($newCost, 4)) {
            return;
        }

        $product->forceFill(['cost' => $newCost])->save();

        CostHistory::create([
            'product_id' => $product->id,
            'old_cost' => $oldCost,
            'new_cost' => $newCost,
            'source' => 'purchase',
            'changed_by' => auth()->id(),
        ]);

        $this->recalculateMarginPrices($product);
    }

    /**
     * Recalcula los precios de las listas por margen.
     *
     * Un precio capturado a mano no se toca: si alguien lo fijo a
     * proposito, un cambio de costo no debe sobrescribirlo.
     */
    protected function recalculateMarginPrices(Product $product): void
    {
        $tenant = auth()->user()->tenant;
        $lists = PriceList::active()->where('pricing_mode', 'margin')->get();

        foreach ($lists as $list) {
            $existing = ProductPrice::where('product_id', $product->id)
                ->where('price_list_id', $list->id)
                ->whereNull('variant_id')
                ->whereNull('product_unit_id')
                ->where('min_quantity', 1)
                ->first();

            if ($existing !== null && $existing->is_manual) {
                continue;
            }

            $price = Pricing::suggest(
                cost: (float) $product->cost,
                marginPercent: (float) $list->margin_percent,
                taxRate: $product->taxRate(),
                mode: $tenant->taxMode(),
                decimals: $tenant->price_decimals,
            );

            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'price_list_id' => $list->id,
                    'variant_id' => null,
                    'product_unit_id' => null,
                    'min_quantity' => 1,
                ],
                [
                    'price' => $price,
                    'margin_percent' => $list->margin_percent,
                    'is_manual' => false,
                ],
            );

            if ((float) ($existing?->price ?? -1) !== $price) {
                PriceHistory::create([
                    'product_id' => $product->id,
                    'price_list_id' => $list->id,
                    'old_price' => $existing?->price,
                    'new_price' => $price,
                    'changed_by' => auth()->id(),
                ]);
            }
        }
    }

    // =========================================================
    // Pago
    // =========================================================

    protected function recordCashPayment(Purchase $purchase, ?string $paymentMethodId): void
    {
        if ($purchase->supplier_id === null) {
            return;
        }

        SupplierPayment::create([
            'supplier_id' => $purchase->supplier_id,
            'purchase_id' => $purchase->id,
            'payment_method_id' => $paymentMethodId,
            'amount' => $purchase->total,
            'notes' => 'Pago de contado',
            'created_by' => auth()->id(),
        ]);
    }

    /** Suma la deuda al proveedor. */
    protected function chargeSupplier(string $supplierId, float $amount): void
    {
        $supplier = Supplier::lockForUpdate()->find($supplierId);

        if ($supplier === null) {
            throw new RuntimeException('Ese proveedor no existe.');
        }

        $supplier->update(['balance' => Pricing::round($supplier->balance + $amount, 2)]);
    }

    /**
     * Abona a una compra a credito y baja el saldo del proveedor.
     */
    public function pay(
        Purchase $purchase,
        float $amount,
        ?string $paymentMethodId = null,
        ?string $reference = null,
    ): SupplierPayment {
        if ($purchase->isCancelled()) {
            throw new RuntimeException('No se puede abonar a una compra anulada.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('El abono debe ser mayor que cero.');
        }

        if ($amount > $purchase->balance()) {
            throw new RuntimeException('El abono supera el saldo de esta compra.');
        }

        return DB::transaction(function () use ($purchase, $amount, $paymentMethodId, $reference) {
            $payment = SupplierPayment::create([
                'supplier_id' => $purchase->supplier_id,
                'purchase_id' => $purchase->id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $amount,
                'reference' => $reference,
                'created_by' => auth()->id(),
            ]);

            $purchase->update(['paid' => Pricing::round($purchase->paid + $amount, 2)]);

            if ($purchase->supplier) {
                $purchase->supplier->update([
                    'balance' => Pricing::round($purchase->supplier->balance - $amount, 2),
                ]);
            }

            return $payment;
        });
    }

    /**
     * Anula una compra: saca la mercancia que entro y quita la deuda.
     *
     * Se niega si ya no hay existencia suficiente, porque devolver algo
     * que ya se vendio dejaria el inventario en negativo sin explicacion.
     */
    public function cancel(Purchase $purchase, string $reason): Purchase
    {
        if ($purchase->isCancelled()) {
            throw new RuntimeException('Esta compra ya estaba anulada.');
        }

        return DB::transaction(function () use ($purchase, $reason) {
            foreach ($purchase->items as $item) {
                if ($item->product === null || ! $item->product->track_stock) {
                    continue;
                }

                $available = (float) Inventory::where('product_id', $item->product_id)
                    ->where('branch_id', $purchase->branch_id)
                    ->where('variant_id', $item->variant_id)
                    ->value('quantity');

                if ($available < $item->base_quantity) {
                    throw new RuntimeException(
                        "Ya se vendio parte de \"{$item->description}\". Ajusta el inventario en lugar de anular.",
                    );
                }

                $this->inventory->move(
                    product: $item->product,
                    branchId: $purchase->branch_id,
                    quantity: -$item->base_quantity,
                    type: 'purchase_return',
                    reason: "Anulacion de compra {$purchase->folio}: {$reason}",
                    variantId: $item->variant_id,
                    lot: $item->lot_id ? ProductLot::find($item->lot_id) : null,
                    referenceType: 'purchase',
                    referenceId: $purchase->id,
                );
            }

            if ($purchase->payment_type === 'credit' && $purchase->supplier) {
                $purchase->supplier->update([
                    'balance' => Pricing::round($purchase->supplier->balance - $purchase->balance(), 2),
                ]);
            }

            $purchase->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $purchase->fresh();
        });
    }

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'purchase'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'C-'],
        );

        return $series->nextFolio();
    }
}
