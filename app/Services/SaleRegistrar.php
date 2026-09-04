<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemPromotion;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra una venta.
 *
 * Todo ocurre en una sola transaccion: folio, lineas, pagos, descuento de
 * inventario y saldo del cliente. Una venta a medias seria peor que una
 * venta rechazada, porque descuadraria el inventario o el dinero sin que
 * nadie se entere.
 */
class SaleRegistrar
{
    public function __construct(
        protected InventoryManager $inventory,
        protected Treasury $treasury,
        protected PromotionEngine $promotions,
    ) {}

    /**
     * @param  array<int, array{
     *     product_id: string, variant_id?: ?string, product_unit_id?: ?string,
     *     quantity: float, unit_price: float, discount?: float
     * }>  $lines
     * @param  array<int, array{
     *     payment_method_id: string, amount: float, reference?: ?string
     * }>  $payments
     */
    public function register(
        Shift $shift,
        array $lines,
        array $payments,
        ?string $customerId = null,
        ?string $priceListId = null,
        ?string $notes = null,
        bool $applyPromotions = true,
    ): Sale {
        if (! $shift->isOpen()) {
            throw new RuntimeException('No hay un turno de caja abierto.');
        }

        if ($lines === []) {
            throw new RuntimeException('La venta no tiene productos.');
        }

        return DB::transaction(function () use ($shift, $lines, $payments, $customerId, $priceListId, $notes, $applyPromotions) {
            $tenant = auth()->user()->tenant;
            $taxMode = $tenant->taxMode();
            $decimals = $tenant->price_decimals;

            // Las promociones se calculan aqui y no se reciben de quien
            // llama: un descuento que viene de afuera es un descuento que
            // se puede inventar.
            //
            // Se apagan al convertir una cotizacion: ahi el precio ya se
            // pacto con el cliente y trae adentro el descuento que se le
            // ofrecio. Volver a aplicar la promocion encima regalaria dos
            // veces la misma rebaja.
            $active = $applyPromotions
                ? $this->promotions->active(
                    branchId: $shift->branch_id,
                    priceListId: $priceListId,
                    customerTypeId: $customerId
                        ? Customer::whereKey($customerId)->value('customer_type_id')
                        : null,
                )
                : collect();

            $prepared = $this->prepareLines($lines, $taxMode, $decimals, $active);
            $totals = $this->sumLines($prepared, $decimals);

            $paymentRows = $this->preparePayments($payments, $decimals);
            $paid = array_sum(array_column($paymentRows, 'amount_primary'));

            $this->assertPaymentCoversTotal($paymentRows, $paid, $totals['total'], $customerId);

            // El cambio solo se calcula sobre lo que se puede devolver.
            // Pagar de mas con tarjeta no genera cambio.
            $changeable = array_sum(array_map(
                fn (array $p) => $p['allows_change'] ? $p['amount_primary'] : 0,
                $paymentRows,
            ));
            $overpaid = Pricing::round(max(0, $paid - $totals['total']), $decimals);
            $change = Pricing::round(min($overpaid, $changeable), $decimals);

            $sale = Sale::create([
                'branch_id' => $shift->branch_id,
                'terminal_id' => $shift->terminal_id,
                'shift_id' => $shift->id,
                'user_id' => auth()->id(),
                'customer_id' => $customerId,
                'price_list_id' => $priceListId,
                'folio' => $this->nextFolio($shift->branch_id),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'paid' => Pricing::round($paid, $decimals),
                'change' => $change,
                'cost_total' => $totals['cost'],
                'status' => 'completed',
                'notes' => $notes,
            ]);

            foreach ($prepared as $position => $line) {
                $item = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'variant_id' => $line['variant_id'],
                    'product_unit_id' => $line['product_unit_id'],
                    'description' => $line['description'],
                    'sku' => $line['product']->sku,
                    'unit_label' => $line['unit_label'],
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_factor' => $line['unit_factor'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $line['totals']['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['totals']['tax'],
                    'net' => $line['totals']['net'],
                    'total' => $line['totals']['gross'],
                    'unit_cost' => $line['unit_cost'],
                    'position' => $position,
                ]);

                $this->recordPromotions($item, $line['promotions']);
                $this->deductStock($sale, $line);
            }

            foreach ($paymentRows as $payment) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $payment['method']->id,
                    'method_label' => $payment['method']->name,
                    'amount' => $payment['amount'],
                    'exchange_rate' => 1,
                    'amount_primary' => $payment['amount_primary'],
                    'reference' => $payment['reference'],
                ]);

                // El dinero cobrado entra a la cuenta de esa forma de pago.
                // El credito no entra a ninguna: todavia no se cobro.
                if (! $payment['is_credit']) {
                    $received = $payment['amount_primary'];

                    // El cambio sale del mismo cajon del que entro el pago,
                    // asi que se descuenta de lo que realmente quedo.
                    if ($payment['allows_change']) {
                        $received = Pricing::round($received - $change, 2);
                    }

                    $this->treasury->postPayment(
                        paymentMethodId: $payment['method']->id,
                        direction: 'in',
                        amount: max(0, $received),
                        description: "Venta {$sale->folio}",
                        source: 'sale',
                        sourceId: $sale->id,
                    );
                }
            }

            $this->chargeCredit($paymentRows, $customerId);

            return $sale->load(['items', 'payments']);
        });
    }

    // =========================================================
    // Anulacion
    // =========================================================

    /**
     * Anula una venta completa.
     *
     * No borra nada: marca la venta, regresa la mercancia con su propio
     * movimiento de inventario, saca de la cuenta el dinero que habia
     * entrado y le quita al cliente el credito que se le cargo.
     *
     * Si la venta ya tuvo devoluciones parciales, se descuenta lo que ya
     * se regreso: sin eso, anular despues de devolver dos piezas meteria
     * al estante mercancia que ya habia vuelto y sacaria de la caja
     * dinero que ya habia salido.
     */
    public function cancel(Sale $sale, string $reason): Sale
    {
        if ($sale->isCancelled()) {
            throw new RuntimeException('Esta venta ya estaba anulada.');
        }

        return DB::transaction(function () use ($sale, $reason) {
            $sale->load(['items.product', 'payments.paymentMethod', 'customer']);

            $this->restoreStock($sale);
            $this->refundMoney($sale);
            $this->releaseCredit($sale);

            $sale->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $sale->fresh();
        });
    }

    /** Regresa al inventario lo que todavia no habia vuelto. */
    protected function restoreStock(Sale $sale): void
    {
        foreach ($sale->items as $item) {
            $pending = $item->returnableQuantity();

            if ($pending <= 0 || $item->product === null || ! $item->product->track_stock) {
                continue;
            }

            $this->inventory->move(
                product: $item->product,
                branchId: $sale->branch_id,
                quantity: Pricing::toBaseQuantity($pending, (float) $item->unit_factor),
                type: 'sale_return',
                reason: "Anulacion de {$sale->folio}",
                variantId: $item->variant_id,
                referenceType: 'sale',
                referenceId: $sale->id,
            );
        }
    }

    /**
     * Saca de las cuentas el dinero que habia entrado por esta venta.
     *
     * Sin esto la cuenta seguiria mostrando un dinero que ya no esta en
     * el cajon, y el corte del dia no cuadraria.
     */
    protected function refundMoney(Sale $sale): void
    {
        // Lo que ya se devolvio en efectivo por notas de credito no se
        // vuelve a sacar: ese dinero salio una vez.
        $alreadyRefunded = (float) $sale->creditNotes()
            ->registered()
            ->where('type', CreditNote::REFUND)
            ->sum('total');

        foreach ($sale->payments as $payment) {
            if ($payment->paymentMethod?->isCredit()) {
                continue;
            }

            $received = (float) $payment->amount_primary;

            // El cambio salio del mismo cajon al cobrar, asi que lo que
            // de verdad quedo ahi fue el pago menos el cambio.
            if ($payment->paymentMethod?->allows_change) {
                $received -= (float) $sale->change;
            }

            $received = max(0, Pricing::round($received, 2));
            $take = Pricing::round(max(0, $received - $alreadyRefunded), 2);
            $alreadyRefunded = Pricing::round(max(0, $alreadyRefunded - $received), 2);

            if ($take <= 0) {
                continue;
            }

            $this->treasury->postPayment(
                paymentMethodId: $payment->payment_method_id,
                direction: 'out',
                amount: $take,
                description: "Anulacion de la venta {$sale->folio}",
                source: 'sale',
                sourceId: $sale->id,
            );
        }
    }

    /** Le quita al cliente el credito que esta venta le cargo. */
    protected function releaseCredit(Sale $sale): void
    {
        if ($sale->customer_id === null) {
            return;
        }

        $charged = (float) $sale->payments
            ->filter(fn (SalePayment $p) => $p->paymentMethod?->isCredit())
            ->sum('amount_primary');

        // El saldo a favor que ya se le dejo por devoluciones tambien
        // salio del mismo saldo; no se le descuenta dos veces.
        $alreadyCredited = (float) $sale->creditNotes()
            ->registered()
            ->where('type', CreditNote::CREDIT)
            ->sum('total');

        $release = Pricing::round(max(0, $charged - $alreadyCredited), 2);

        if ($release <= 0) {
            return;
        }

        $customer = Customer::lockForUpdate()->find($sale->customer_id);

        if ($customer === null) {
            return;
        }

        $customer->update([
            'balance' => Pricing::round($customer->balance - $release, 2),
        ]);
    }

    // =========================================================
    // Lineas
    // =========================================================

    /**
     * Calcula cada linea y trae consigo el producto, para no volver a
     * consultarlo al descontar el inventario.
     */
    protected function prepareLines(
        array $lines,
        string $taxMode,
        int $decimals,
        ?Collection $active = null,
    ): array {
        $active = $active ?? collect();
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

            if ($quantity <= 0) {
                throw new RuntimeException("La cantidad de \"{$product->name}\" debe ser mayor que cero.");
            }

            $taxRate = $product->taxRate();
            $unitPrice = (float) $line['unit_price'];

            $promotion = $this->promotions->forLine(
                product: $product,
                quantity: $quantity,
                unitPrice: $unitPrice,
                promotions: $active,
                decimals: $decimals,
            );

            // El descuento a mano y el de promocion se suman. Pricing::line
            // topa el total al importe de la linea, asi que las dos cosas
            // juntas no la pueden dejar en negativo.
            $totals = Pricing::line(
                quantity: $quantity,
                unitPrice: $unitPrice,
                taxRate: $taxRate,
                discount: (float) ($line['discount'] ?? 0) + $promotion['discount'],
                mode: $taxMode,
                decimals: $decimals,
            );

            $prepared[] = [
                'product' => $product,
                'variant_id' => $line['variant_id'] ?? null,
                'product_unit_id' => $unit?->id,
                'unit_label' => $unit?->unit?->name,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_factor' => $factor,
                'base_quantity' => Pricing::toBaseQuantity($quantity, $factor),
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'unit_cost' => (float) $product->cost,
                'totals' => $totals,
                'promotions' => $promotion['applied'],
            ];
        }

        return $prepared;
    }

    /**
     * Deja constancia de que promocion se aplico a la linea.
     *
     * El nombre se copia: renombrar o borrar la promocion despues no debe
     * cambiar como se lee un ticket ya emitido. El contador de usos sube
     * aqui, que es el unico lugar donde una promocion se usa de verdad.
     */
    protected function recordPromotions(SaleItem $item, array $applied): void
    {
        foreach ($applied as $row) {
            SaleItemPromotion::create([
                'sale_item_id' => $item->id,
                'promotion_id' => $row['promotion']->id,
                'label' => $row['label'],
                'discount' => $row['discount'],
                'free_quantity' => $row['free_quantity'],
            ]);

            $row['promotion']->increment('times_used');
        }
    }

    protected function sumLines(array $prepared, int $decimals): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $cost = 0;

        foreach ($prepared as $line) {
            $subtotal += $line['totals']['subtotal'];
            $discount += $line['totals']['discount'];
            $tax += $line['totals']['tax'];
            $total += $line['totals']['gross'];
            $cost += $line['unit_cost'] * $line['base_quantity'];
        }

        return [
            'subtotal' => Pricing::round($subtotal, $decimals),
            'discount' => Pricing::round($discount, $decimals),
            'tax' => Pricing::round($tax, $decimals),
            'total' => Pricing::round($total, $decimals),
            'cost' => Pricing::round($cost, 4),
        ];
    }

    /**
     * Descuenta la mercancia vendida.
     *
     * Con lotes se reparte por FEFO; sin lotes se descuenta directo. Un
     * servicio no descuenta nada.
     */
    protected function deductStock(Sale $sale, array $line): void
    {
        $product = $line['product'];

        if (! $product->track_stock) {
            return;
        }

        if ($product->track_lots) {
            $this->inventory->consumeFefo(
                product: $product,
                branchId: $sale->branch_id,
                quantity: $line['base_quantity'],
                type: 'sale',
                variantId: $line['variant_id'],
                referenceType: 'sale',
                referenceId: $sale->id,
                reason: "Venta {$sale->folio}",
            );

            return;
        }

        $this->inventory->move(
            product: $product,
            branchId: $sale->branch_id,
            quantity: -$line['base_quantity'],
            type: 'sale',
            reason: "Venta {$sale->folio}",
            variantId: $line['variant_id'],
            referenceType: 'sale',
            referenceId: $sale->id,
        );
    }

    // =========================================================
    // Pagos
    // =========================================================

    protected function preparePayments(array $payments, int $decimals): array
    {
        if ($payments === []) {
            throw new RuntimeException('La venta no tiene forma de pago.');
        }

        $rows = [];

        foreach ($payments as $payment) {
            $method = PaymentMethod::find($payment['payment_method_id']);

            if ($method === null) {
                throw new RuntimeException('Esa forma de pago no existe.');
            }

            $amount = Pricing::round((float) $payment['amount'], $decimals);

            if ($amount <= 0) {
                continue;
            }

            $rows[] = [
                'method' => $method,
                'amount' => $amount,
                'amount_primary' => $amount,
                'allows_change' => $method->allows_change,
                'is_credit' => $method->isCredit(),
                'reference' => $payment['reference'] ?? null,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('El monto pagado debe ser mayor que cero.');
        }

        return $rows;
    }

    /**
     * El cobro tiene que cubrir el total, salvo que la diferencia se vaya
     * a credito de un cliente identificado.
     */
    protected function assertPaymentCoversTotal(
        array $paymentRows,
        float $paid,
        float $total,
        ?string $customerId,
    ): void {
        if ($paid >= $total) {
            return;
        }

        $hasCredit = collect($paymentRows)->contains(fn (array $p) => $p['is_credit']);

        if (! $hasCredit) {
            throw new RuntimeException('El pago no cubre el total de la venta.');
        }

        if ($customerId === null) {
            throw new RuntimeException('Una venta a credito necesita un cliente.');
        }
    }

    /**
     * Suma al saldo del cliente lo que se llevo a credito, validando su
     * limite. Sin esta comprobacion el credito no seria un limite sino
     * una sugerencia.
     */
    protected function chargeCredit(array $paymentRows, ?string $customerId): void
    {
        $credit = array_sum(array_map(
            fn (array $p) => $p['is_credit'] ? $p['amount_primary'] : 0,
            $paymentRows,
        ));

        if ($credit <= 0) {
            return;
        }

        $customer = Customer::lockForUpdate()->find($customerId);

        if ($customer === null) {
            throw new RuntimeException('Una venta a credito necesita un cliente.');
        }

        if (! $customer->credit_enabled) {
            throw new RuntimeException("{$customer->name} no tiene credito autorizado.");
        }

        $newBalance = Pricing::round($customer->balance + $credit, 2);

        if ($customer->credit_limit > 0 && $newBalance > $customer->credit_limit) {
            throw new RuntimeException(
                "La venta supera el limite de credito de {$customer->name}.",
            );
        }

        $customer->update(['balance' => $newBalance]);
    }

    // =========================================================
    // Folio
    // =========================================================

    /**
     * Toma el siguiente folio de la serie de la sucursal, creandola si
     * hace falta. La serie bloquea su fila, asi que dos cajas vendiendo a
     * la vez nunca se llevan el mismo numero.
     */
    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'sale'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'V-'],
        );

        return $series->nextFolio();
    }
}
