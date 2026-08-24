<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Devoluciones de venta.
 *
 * La venta original nunca se edita: se emite una nota de credito que dice
 * que volvio, cuanto se le regreso al cliente y como. Asi el corte del dia
 * en que se vendio sigue siendo el que fue, y el reporte de un mes ya
 * cerrado no cambia porque alguien devolvio algo en el siguiente.
 *
 * Todo ocurre en una transaccion: mercancia, dinero y documento. Una
 * devolucion a medias descuadraria el inventario o la caja sin que nadie
 * se entere.
 */
class ReturnRegistrar
{
    public function __construct(
        protected InventoryManager $inventory,
        protected Treasury $treasury,
    ) {}

    /**
     * Registra la devolucion de una o varias lineas de una venta.
     *
     * @param  array<int, array{sale_item_id: string, quantity: float}>  $lines
     */
    public function register(
        Sale $sale,
        array $lines,
        string $reason,
        string $type = CreditNote::REFUND,
        ?string $paymentMethodId = null,
        bool $restock = true,
        ?Shift $shift = null,
        ?string $notes = null,
    ): CreditNote {
        if ($sale->isCancelled()) {
            throw new RuntimeException('Esa venta esta anulada: no hay nada que devolver.');
        }

        if (! in_array($type, [CreditNote::REFUND, CreditNote::CREDIT], true)) {
            throw new RuntimeException('Tipo de devolucion invalido.');
        }

        if ($type === CreditNote::CREDIT && $sale->customer_id === null) {
            throw new RuntimeException('Para dejar saldo a favor hace falta un cliente identificado.');
        }

        return DB::transaction(function () use (
            $sale, $lines, $reason, $type, $paymentMethodId, $restock, $shift, $notes
        ) {
            $decimals = auth()->user()->tenant->price_decimals;

            $prepared = $this->prepareLines($sale, $lines, $decimals);

            if ($prepared === []) {
                throw new RuntimeException('Indica que se devuelve y en que cantidad.');
            }

            $totals = $this->sumLines($prepared, $decimals);

            $note = CreditNote::create([
                'branch_id' => $sale->branch_id,
                'shift_id' => $shift?->id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'user_id' => auth()->id(),
                'folio' => $this->nextFolio($sale->branch_id),
                'type' => $type,
                'payment_method_id' => $type === CreditNote::REFUND ? $paymentMethodId : null,
                'restock' => $restock,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'cost_total' => $totals['cost'],
                'reason' => $reason,
                'notes' => $notes,
                'status' => 'registered',
            ]);

            foreach ($prepared as $position => $line) {
                CreditNoteItem::create([
                    'credit_note_id' => $note->id,
                    'sale_item_id' => $line['sale_item']->id,
                    'product_id' => $line['sale_item']->product_id,
                    'variant_id' => $line['sale_item']->variant_id,
                    'product_unit_id' => $line['sale_item']->product_unit_id,
                    'description' => $line['sale_item']->description,
                    'sku' => $line['sale_item']->sku,
                    'unit_label' => $line['sale_item']->unit_label,
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_factor' => $line['sale_item']->unit_factor,
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['sale_item']->tax_rate,
                    'tax_amount' => $line['totals']['tax'],
                    'net' => $line['totals']['net'],
                    'total' => $line['totals']['gross'],
                    'unit_cost' => $line['sale_item']->unit_cost,
                    'position' => $position,
                ]);

                if ($restock) {
                    $this->restock($note, $line);
                }
            }

            $this->settle($note);

            return $note->load('items');
        });
    }

    /**
     * Devuelve la venta entera: es lo que hace la anulacion.
     *
     * Se arma con lo que quede por devolver de cada linea, de modo que
     * anular despues de una devolucion parcial no regrese dos veces la
     * misma mercancia.
     */
    public function returnEverything(
        Sale $sale,
        string $reason,
        string $type = CreditNote::REFUND,
        ?string $paymentMethodId = null,
    ): ?CreditNote {
        $lines = $sale->items
            ->map(fn (SaleItem $item) => [
                'sale_item_id' => $item->id,
                'quantity' => $item->returnableQuantity(),
            ])
            ->filter(fn (array $line) => $line['quantity'] > 0)
            ->values()
            ->all();

        if ($lines === []) {
            return null;
        }

        return $this->register(
            sale: $sale,
            lines: $lines,
            reason: $reason,
            type: $type,
            paymentMethodId: $paymentMethodId,
        );
    }

    // =========================================================
    // Lineas
    // =========================================================

    /**
     * Valida cada linea contra lo que queda por devolver y calcula sus
     * importes al precio que el cliente realmente pago.
     */
    protected function prepareLines(Sale $sale, array $lines, int $decimals): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $item = SaleItem::with('product')
                ->where('sale_id', $sale->id)
                ->find($line['sale_item_id']);

            if ($item === null) {
                throw new RuntimeException('Esa linea no pertenece a la venta.');
            }

            $pending = $item->returnableQuantity();

            if ($quantity > $pending + 0.0005) {
                throw new RuntimeException(
                    "De \"{$item->description}\" solo quedan ".
                    rtrim(rtrim(number_format($pending, 3), '0'), '.').
                    ' por devolver.',
                );
            }

            // Se devuelve lo que se pago, no el precio de lista: si la
            // linea llevaba descuento o promocion, el cliente entrego
            // menos y eso es lo que le toca de vuelta.
            $unitPrice = $item->effectiveUnitPrice();

            $totals = Pricing::splitTax(
                Pricing::round($quantity * $unitPrice, $decimals),
                (float) $item->tax_rate,
                auth()->user()->tenant->taxMode(),
                $decimals,
            );

            $prepared[] = [
                'sale_item' => $item,
                'quantity' => Pricing::round($quantity, 3),
                'base_quantity' => Pricing::toBaseQuantity($quantity, (float) $item->unit_factor),
                'unit_price' => $unitPrice,
                'totals' => $totals,
            ];
        }

        return $prepared;
    }

    protected function sumLines(array $prepared, int $decimals): array
    {
        $subtotal = 0;
        $tax = 0;
        $total = 0;
        $cost = 0;

        foreach ($prepared as $line) {
            $subtotal += $line['totals']['net'];
            $tax += $line['totals']['tax'];
            $total += $line['totals']['gross'];
            $cost += $line['sale_item']->unit_cost * $line['base_quantity'];
        }

        return [
            'subtotal' => Pricing::round($subtotal, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
            'cost' => Pricing::round($cost, 4),
        ];
    }

    /**
     * Regresa la mercancia al inventario.
     *
     * Un producto con lotes vuelve sin lote asignado: no hay forma de
     * saber de cual salio, y adivinarlo falsearia el vencimiento del lote
     * al que se le sume.
     */
    protected function restock(CreditNote $note, array $line): void
    {
        $product = $line['sale_item']->product;

        if ($product === null || ! $product->track_stock) {
            return;
        }

        $this->inventory->move(
            product: $product,
            branchId: $note->branch_id,
            quantity: $line['base_quantity'],
            type: 'sale_return',
            reason: "Devolucion {$note->folio}",
            variantId: $line['sale_item']->variant_id,
            referenceType: 'credit_note',
            referenceId: $note->id,
        );
    }

    // =========================================================
    // Dinero
    // =========================================================

    /**
     * Le regresa el dinero al cliente, o se lo deja a favor.
     *
     * El saldo a favor baja lo que el cliente debe y puede dejarlo en
     * negativo: eso es exactamente lo que significa tener saldo a favor.
     */
    protected function settle(CreditNote $note): void
    {
        if ($note->total <= 0) {
            return;
        }

        if ($note->type === CreditNote::CREDIT) {
            $customer = Customer::lockForUpdate()->find($note->customer_id);

            if ($customer === null) {
                throw new RuntimeException('Ese cliente ya no existe.');
            }

            $customer->update([
                'balance' => Pricing::round($customer->balance - $note->total, 2),
            ]);

            return;
        }

        $methodId = $note->payment_method_id
            ?? $this->defaultRefundMethod($note)
            ?? PaymentMethod::where('type', 'cash')->value('id');

        if ($methodId === null) {
            return;
        }

        $this->treasury->postPayment(
            paymentMethodId: $methodId,
            direction: 'out',
            amount: (float) $note->total,
            description: "Devolucion {$note->folio}",
            source: 'credit_note',
            sourceId: $note->id,
        );
    }

    /**
     * Por que via se devuelve el dinero cuando nadie lo indica.
     *
     * Se toma la forma de pago con la que mas se pago la venta: devolver
     * por donde entro el dinero es lo que espera cualquiera que despues
     * tenga que cuadrar la caja.
     */
    protected function defaultRefundMethod(CreditNote $note): ?string
    {
        return $note->sale?->payments()
            ->orderByDesc('amount_primary')
            ->value('payment_method_id');
    }

    // =========================================================
    // Folio
    // =========================================================

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'credit_note'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'NC-'],
        );

        return $series->nextFolio();
    }
}
