<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cuenta de credito de un cliente.
 *
 * El saldo de `customers.balance` es la cifra que se consulta a diario, y
 * los movimientos que la explican viven repartidos entre ventas a credito
 * y abonos. Este servicio es el unico que la mueve al recibir dinero, y
 * el que arma el estado de cuenta que se le ensena al cliente.
 */
class CustomerAccount
{
    public function __construct(protected Treasury $treasury) {}

    /**
     * Recibe un abono y baja el saldo.
     *
     * Si se indica el turno, el abono cuenta en el arqueo de caja cuando
     * la forma de pago entra al cajon: el dinero esta ahi fisicamente.
     */
    public function receivePayment(
        Customer $customer,
        float $amount,
        ?string $paymentMethodId = null,
        ?string $saleId = null,
        ?Shift $shift = null,
        ?string $reference = null,
        ?string $notes = null,
    ): CustomerPayment {
        if ($amount <= 0) {
            throw new RuntimeException('El abono debe ser mayor que cero.');
        }

        return DB::transaction(function () use (
            $customer, $amount, $paymentMethodId, $saleId, $shift, $reference, $notes
        ) {
            $locked = Customer::lockForUpdate()->findOrFail($customer->id);

            if ($amount > $locked->balance) {
                throw new RuntimeException(
                    'El abono supera lo que debe el cliente ('
                    .number_format($locked->balance, 2).').',
                );
            }

            $payment = CustomerPayment::create([
                'customer_id' => $locked->id,
                'sale_id' => $saleId,
                'payment_method_id' => $paymentMethodId,
                'shift_id' => $shift?->id,
                'amount' => $amount,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $locked->update(['balance' => Pricing::round($locked->balance - $amount, 2)]);

            $this->treasury->postPayment(
                paymentMethodId: $paymentMethodId,
                direction: 'in',
                amount: $amount,
                description: "Abono de {$locked->name}",
                source: 'customer_payment',
                sourceId: $payment->id,
            );

            return $payment;
        });
    }

    /**
     * Estado de cuenta: cargos y abonos en orden, con el saldo corrido.
     *
     * Se arma en memoria a partir de las dos fuentes porque no hay una
     * tabla de movimientos: una venta a credito es el cargo y el abono es
     * el pago. Tenerlas separadas evita duplicar el dato que ya viven en
     * la venta.
     *
     * @return array<int, array{date, type, reference, charge, payment, balance, id}>
     */
    public function statement(Customer $customer, int $limit = 100): array
    {
        $charges = $this->creditSales($customer)
            ->map(fn (Sale $sale) => [
                'date' => $sale->created_at,
                'type' => 'sale',
                'label' => 'Venta a credito',
                'reference' => $sale->folio,
                'id' => $sale->id,
                'charge' => $this->creditPortion($sale),
                'payment' => 0.0,
            ]);

        $payments = CustomerPayment::with('paymentMethod')
            ->where('customer_id', $customer->id)
            ->get()
            ->map(fn (CustomerPayment $payment) => [
                'date' => $payment->created_at,
                'type' => 'payment',
                'label' => 'Abono'.($payment->paymentMethod ? " ({$payment->paymentMethod->name})" : ''),
                'reference' => $payment->reference ?? '',
                'id' => $payment->id,
                'charge' => 0.0,
                'payment' => (float) $payment->amount,
            ]);

        $rows = $charges->concat($payments)
            ->sortBy('date')
            ->values();

        // El saldo corrido se calcula hacia adelante, para que cada linea
        // muestre como quedaba la cuenta en ese momento.
        $running = 0.0;

        $rows = $rows->map(function (array $row) use (&$running) {
            $running = Pricing::round($running + $row['charge'] - $row['payment'], 2);
            $row['balance'] = $running;

            return $row;
        });

        // Se muestran las mas recientes primero, pero el saldo corrido ya
        // quedo calculado en el orden correcto.
        return $rows->reverse()->take($limit)->values()->all();
    }

    /** Ventas a credito del cliente, sin las anuladas. */
    public function creditSales(Customer $customer)
    {
        return Sale::with('payments.paymentMethod')
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->whereHas('payments.paymentMethod', fn ($q) => $q->where('type', 'credit'))
            ->orderBy('created_at')
            ->get();
    }

    /** Cuanto de una venta se fue a credito. */
    public function creditPortion(Sale $sale): float
    {
        return Pricing::round(
            $sale->payments
                ->filter(fn (SalePayment $p) => $p->paymentMethod?->isCredit())
                ->sum('amount_primary'),
            2,
        );
    }

    /**
     * Cuanto credito le queda disponible al cliente.
     * Null cuando no tiene limite fijado.
     */
    public function availableCredit(Customer $customer): ?float
    {
        if (! $customer->credit_enabled) {
            return 0.0;
        }

        if ($customer->credit_limit <= 0) {
            return null;
        }

        return Pricing::round(max(0, $customer->credit_limit - $customer->balance), 2);
    }

    /** Formas de pago con las que se puede recibir un abono. */
    public function paymentMethods()
    {
        return PaymentMethod::active()
            ->where('type', '!=', 'credit')
            ->orderBy('position')
            ->get();
    }
}
