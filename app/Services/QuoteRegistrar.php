<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cotizaciones: armar, responder y convertir en venta.
 *
 * Una cotizacion no toca inventario ni dinero. Es una promesa de precio
 * con fecha de caducidad, y por eso lo unico que guarda con cuidado es
 * cuanto se ofrecio y hasta cuando.
 */
class QuoteRegistrar
{
    public function __construct(
        protected SaleRegistrar $sales,
    ) {}

    // =========================================================
    // Alta y edicion
    // =========================================================

    /**
     * @param  array<int, array{
     *     product_id: string, variant_id?: ?string, product_unit_id?: ?string,
     *     quantity: float, unit_price: float, discount?: float
     * }>  $lines
     */
    public function create(
        string $branchId,
        array $lines,
        string $customerName,
        ?string $customerId = null,
        ?string $customerPhone = null,
        ?string $priceListId = null,
        ?string $validUntil = null,
        ?string $notes = null,
    ): Quote {
        if ($lines === []) {
            throw new RuntimeException('La cotizacion no tiene productos.');
        }

        return DB::transaction(function () use (
            $branchId, $lines, $customerName, $customerId,
            $customerPhone, $priceListId, $validUntil, $notes,
        ) {
            $quote = Quote::create([
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                // Si viene de una ficha de cliente se copia su nombre, para
                // que la cotizacion se siga leyendo igual aunque despues
                // ese cliente se renombre o se borre.
                'customer_name' => $customerId
                    ? (Customer::whereKey($customerId)->value('name') ?: $customerName)
                    : $customerName,
                'customer_phone' => $customerPhone,
                'price_list_id' => $priceListId,
                'folio' => $this->nextFolio($branchId),
                'status' => Quote::PENDING,
                'valid_until' => $validUntil ?: now()->addDays(15)->toDateString(),
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($quote, $lines);

            return $quote->load('items');
        });
    }

    /**
     * Reemplaza las lineas y los datos de una cotizacion pendiente.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function update(
        Quote $quote,
        array $lines,
        string $customerName,
        ?string $customerId = null,
        ?string $customerPhone = null,
        ?string $priceListId = null,
        ?string $validUntil = null,
        ?string $notes = null,
    ): Quote {
        if (! $quote->isEditable()) {
            throw new RuntimeException('Solo se puede editar una cotizacion pendiente.');
        }

        if ($lines === []) {
            throw new RuntimeException('La cotizacion no tiene productos.');
        }

        return DB::transaction(function () use (
            $quote, $lines, $customerName, $customerId,
            $customerPhone, $priceListId, $validUntil, $notes,
        ) {
            $quote->update([
                'customer_id' => $customerId,
                'customer_name' => $customerId
                    ? (Customer::whereKey($customerId)->value('name') ?: $customerName)
                    : $customerName,
                'customer_phone' => $customerPhone,
                'price_list_id' => $priceListId,
                'valid_until' => $validUntil ?: $quote->valid_until,
                'notes' => $notes,
            ]);

            // Se borran y se vuelven a escribir en lugar de conciliar linea
            // por linea: una cotizacion pendiente no la referencia nadie,
            // asi que no hay historia que preservar.
            $quote->items()->delete();
            $this->writeLines($quote, $lines);

            return $quote->fresh('items');
        });
    }

    // =========================================================
    // Respuesta del cliente
    // =========================================================

    public function approve(Quote $quote): Quote
    {
        if (! $quote->isPending()) {
            throw new RuntimeException('Solo se puede aprobar una cotizacion pendiente.');
        }

        $quote->update([
            'status' => Quote::APPROVED,
            'answered_at' => now(),
            'answered_by' => auth()->id(),
            'reject_reason' => null,
        ]);

        return $quote;
    }

    public function reject(Quote $quote, string $reason): Quote
    {
        if ($quote->isConverted()) {
            throw new RuntimeException('Esta cotizacion ya se convirtio en venta.');
        }

        if ($quote->isRejected()) {
            throw new RuntimeException('Esta cotizacion ya estaba rechazada.');
        }

        $quote->update([
            'status' => Quote::REJECTED,
            'answered_at' => now(),
            'answered_by' => auth()->id(),
            'reject_reason' => $reason,
        ]);

        return $quote;
    }

    /** Regresa a pendiente una cotizacion rechazada, para volver a negociarla. */
    public function reopen(Quote $quote): Quote
    {
        if (! $quote->isRejected()) {
            throw new RuntimeException('Solo se reabre una cotizacion rechazada.');
        }

        $quote->update([
            'status' => Quote::PENDING,
            'answered_at' => null,
            'answered_by' => null,
            'reject_reason' => null,
        ]);

        return $quote;
    }

    // =========================================================
    // Conversion en venta
    // =========================================================

    /**
     * Convierte la cotizacion en una venta real.
     *
     * La venta se registra con los precios pactados, no con los del
     * catalogo de hoy: eso es exactamente lo que el cliente vino a
     * cobrar. Por lo mismo se le pide a SaleRegistrar que no aplique
     * promociones encima.
     *
     * @param  array<int, array{payment_method_id: string, amount: float, reference?: ?string}>  $payments
     */
    public function convert(Quote $quote, Shift $shift, array $payments): Sale
    {
        if ($quote->isConverted()) {
            throw new RuntimeException('Esta cotizacion ya se convirtio en venta.');
        }

        if ($quote->isRejected()) {
            throw new RuntimeException('Una cotizacion rechazada no se convierte en venta.');
        }

        if ($quote->isExpired()) {
            throw new RuntimeException(
                'Esta cotizacion vencio el '.$quote->valid_until->format('d/m/Y')
                .'. Reabrela con una fecha nueva antes de convertirla.'
            );
        }

        return DB::transaction(function () use ($quote, $shift, $payments) {
            $lines = $quote->items->map(fn (QuoteItem $item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'product_unit_id' => $item->product_unit_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => $item->discount,
            ])->all();

            $sale = $this->sales->register(
                shift: $shift,
                lines: $lines,
                payments: $payments,
                customerId: $quote->customer_id,
                priceListId: $quote->price_list_id,
                notes: "Cotizacion {$quote->folio}",
                applyPromotions: false,
            );

            $quote->update([
                'status' => Quote::CONVERTED,
                'sale_id' => $sale->id,
                'converted_at' => now(),
                'answered_at' => $quote->answered_at ?? now(),
                'answered_by' => $quote->answered_by ?? auth()->id(),
            ]);

            return $sale;
        });
    }

    /**
     * Vuelve a poner vigente una cotizacion vencida, con fecha nueva.
     *
     * Los precios no se recalculan: si el negocio quiere cobrar los de
     * hoy, edita las lineas. Sostener el precio viejo o no es una
     * decision del negocio, no un efecto secundario de una fecha.
     */
    public function extend(Quote $quote, string $validUntil): Quote
    {
        if ($quote->isConverted()) {
            throw new RuntimeException('Esta cotizacion ya se convirtio en venta.');
        }

        $quote->update(['valid_until' => $validUntil]);

        return $quote;
    }

    // =========================================================
    // Interno
    // =========================================================

    /** @param array<int, array<string, mixed>> $lines */
    protected function writeLines(Quote $quote, array $lines): void
    {
        $tenant = auth()->user()->tenant;
        $taxMode = $tenant->taxMode();
        $decimals = $tenant->price_decimals;

        $subtotal = $discount = $tax = $total = 0;

        foreach (array_values($lines) as $position => $line) {
            $product = Product::with(['tax', 'units.unit'])->find($line['product_id']);

            if ($product === null) {
                throw new RuntimeException('Uno de los productos ya no existe.');
            }

            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                throw new RuntimeException("La cantidad de \"{$product->name}\" debe ser mayor que cero.");
            }

            $productUnitId = $line['product_unit_id'] ?? null;
            $unit = $productUnitId
                ? $product->units->firstWhere('id', $productUnitId)
                : $product->defaultUnit();

            $factor = (float) ($unit?->factor ?? 1);
            $taxRate = $product->taxRate();

            $totals = Pricing::line(
                quantity: $quantity,
                unitPrice: (float) $line['unit_price'],
                taxRate: $taxRate,
                discount: (float) ($line['discount'] ?? 0),
                mode: $taxMode,
                decimals: $decimals,
            );

            QuoteItem::create([
                'quote_id' => $quote->id,
                'product_id' => $product->id,
                'variant_id' => $line['variant_id'] ?? null,
                'product_unit_id' => $unit?->id,
                'description' => $product->name,
                'sku' => $product->sku,
                'unit_label' => $unit?->unit?->name,
                'quantity' => $quantity,
                'unit_factor' => $factor,
                'base_quantity' => Pricing::toBaseQuantity($quantity, $factor),
                'unit_price' => (float) $line['unit_price'],
                'discount' => $totals['discount'],
                'tax_rate' => $taxRate,
                'tax_amount' => $totals['tax'],
                'net' => $totals['net'],
                'total' => $totals['gross'],
                'position' => $position,
            ]);

            $subtotal += $totals['subtotal'];
            $discount += $totals['discount'];
            $tax += $totals['tax'];
            $total += $totals['gross'];
        }

        $quote->update([
            'subtotal' => Pricing::round($subtotal, $decimals),
            'discount' => Pricing::round($discount, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
        ]);
    }

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'quote'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'COT-'],
        );

        return $series->nextFolio();
    }
}
