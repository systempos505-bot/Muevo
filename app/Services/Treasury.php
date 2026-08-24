<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMovement;
use App\Models\AccountTransfer;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Movimientos de dinero entre cuentas.
 *
 * Es el unico punto por el que cambia el saldo de una cuenta. Actualizar
 * el saldo y escribir el movimiento tienen que ocurrir juntos o no
 * ocurrir; si cada modulo lo hiciera por su cuenta, tarde o temprano
 * habria dinero sin explicacion.
 *
 * La fila de la cuenta se bloquea mientras se actualiza, para que dos
 * cobros simultaneos no lean el mismo saldo.
 */
class Treasury
{
    /**
     * Registra una entrada o salida y devuelve el movimiento.
     *
     * @param  float  $amount  siempre positivo; la direccion la da $direction
     */
    public function move(
        Account $account,
        string $direction,
        float $amount,
        string $description,
        string $source = 'manual',
        ?string $sourceId = null,
        ?string $reference = null,
    ): AccountMovement {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new RuntimeException('Direccion de movimiento invalida.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('El monto debe ser mayor que cero.');
        }

        return DB::transaction(function () use (
            $account, $direction, $amount, $description, $source, $sourceId, $reference
        ) {
            $locked = Account::with('currency')->lockForUpdate()->findOrFail($account->id);

            $signed = $direction === 'in' ? $amount : -$amount;
            $balance = Pricing::round($locked->balance + $signed, 2);

            $locked->update(['balance' => $balance]);

            // El tipo de cambio se guarda con el movimiento: un reporte de
            // hace seis meses no debe cambiar porque hoy subio el dolar.
            $rate = (float) ($locked->currency?->rate ?? 1);

            return AccountMovement::create([
                'account_id' => $locked->id,
                'direction' => $direction,
                'amount' => Pricing::round($amount, 2),
                'exchange_rate' => $rate,
                'amount_primary' => Pricing::round($amount * $rate, 2),
                'balance' => $balance,
                'source' => $source,
                'source_id' => $sourceId,
                'description' => $description,
                'reference' => $reference,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Traslada dinero de una cuenta a otra.
     *
     * Si las cuentas tienen monedas distintas, el monto que llega se
     * calcula con la tasa de cada moneda y queda guardado, para que el
     * traslado se pueda auditar tal como se hizo.
     */
    public function transfer(
        Account $from,
        Account $to,
        float $amount,
        ?string $description = null,
        ?float $rateOverride = null,
    ): AccountTransfer {
        if ($from->id === $to->id) {
            throw new RuntimeException('Elige dos cuentas distintas.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('El monto debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $description, $rateOverride) {
            // El saldo se comprueba con la fila bloqueada y recien leida,
            // no con el modelo que llego: pudo quedar viejo, y entre la
            // comprobacion y el traslado podria colarse otro movimiento.
            $from = Account::with('currency')->lockForUpdate()->findOrFail($from->id);
            $to = Account::with('currency')->findOrFail($to->id);

            if ($amount > $from->balance) {
                throw new RuntimeException("No hay tanto saldo en {$from->name}.");
            }

            $rate = $rateOverride ?? $this->conversionRate($from, $to);
            $amountTo = Pricing::round($amount * $rate, 2);

            $label = $description ?: "Traslado de {$from->name} a {$to->name}";

            $transfer = AccountTransfer::create([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount_from' => Pricing::round($amount, 2),
                'amount_to' => $amountTo,
                'exchange_rate' => $rate,
                'description' => $label,
                'created_by' => auth()->id(),
            ]);

            $this->move($from, 'out', $amount, $label, 'transfer', $transfer->id);
            $this->move($to, 'in', $amountTo, $label, 'transfer', $transfer->id);

            return $transfer;
        });
    }

    /**
     * Cuantas unidades de la moneda destino equivalen a una de la origen.
     *
     * Las tasas estan expresadas contra la moneda principal, asi que se
     * pasa por ella: origen -> principal -> destino.
     */
    public function conversionRate(Account $from, Account $to): float
    {
        $fromRate = (float) ($from->currency?->rate ?? 1);
        $toRate = (float) ($to->currency?->rate ?? 1);

        if ($fromRate <= 0 || $toRate <= 0) {
            return 1.0;
        }

        return round($fromRate / $toRate, 6);
    }

    /**
     * Abona a la cuenta ligada a una forma de pago.
     *
     * Devuelve null cuando la forma de pago no tiene cuenta asignada: el
     * negocio puede no querer llevar tesoreria y el sistema no debe
     * obligarlo.
     */
    public function postPayment(
        ?string $paymentMethodId,
        string $direction,
        float $amount,
        string $description,
        string $source,
        ?string $sourceId = null,
    ): ?AccountMovement {
        if ($paymentMethodId === null || $amount <= 0) {
            return null;
        }

        $accountId = PaymentMethod::whereKey($paymentMethodId)->value('account_id');

        if ($accountId === null) {
            return null;
        }

        $account = Account::find($accountId);

        if ($account === null || $account->status !== 'active') {
            return null;
        }

        return $this->move($account, $direction, $amount, $description, $source, $sourceId);
    }

    /**
     * Flujo de dinero de un periodo: cuanto entro, cuanto salio y de que.
     *
     * @return array{in: float, out: float, net: float, by_source: array<string, float>}
     */
    public function cashFlow(?string $from = null, ?string $to = null, ?string $accountId = null): array
    {
        $query = AccountMovement::query()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId));

        $rows = (clone $query)
            ->selectRaw('direction, source, coalesce(sum(amount_primary), 0) as total')
            ->groupBy('direction', 'source')
            ->get();

        $in = 0.0;
        $out = 0.0;
        $bySource = [];

        foreach ($rows as $row) {
            $total = (float) $row->total;

            if ($row->direction === 'in') {
                $in += $total;
            } else {
                $out += $total;
            }

            // Los traslados no son ingreso ni gasto: el dinero solo cambia
            // de bolsillo, asi que no ensucian el desglose por origen.
            if ($row->source !== 'transfer') {
                $key = $row->source;
                $bySource[$key] = ($bySource[$key] ?? 0) + ($row->direction === 'in' ? $total : -$total);
            }
        }

        return [
            'in' => Pricing::round($in, 2),
            'out' => Pricing::round($out, 2),
            'net' => Pricing::round($in - $out, 2),
            'by_source' => array_map(fn ($v) => Pricing::round($v, 2), $bySource),
        ];
    }

    /** Suma de todos los saldos, convertidos a moneda principal. */
    public function totalBalance(): float
    {
        return Pricing::round(
            Account::active()->with('currency')->get()->sum(fn (Account $a) => $a->balanceInPrimary()),
            2,
        );
    }
}
