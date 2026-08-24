<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DocumentSeries;
use App\Models\Expense;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro y anulacion de gastos.
 *
 * Un gasto sale de una cuenta y baja su saldo. Las dos cosas ocurren
 * juntas: un gasto sin movimiento seria dinero que desaparece sin
 * explicacion, y un movimiento sin gasto seria una salida sin motivo.
 */
class ExpenseRegistrar
{
    public function __construct(protected Treasury $treasury) {}

    public function register(
        float $total,
        string $description,
        ?string $categoryId = null,
        ?string $accountId = null,
        ?string $supplierId = null,
        ?string $expenseDate = null,
        float $tax = 0,
        ?string $reference = null,
        ?string $notes = null,
        bool $isRecurring = false,
        ?string $branchId = null,
    ): Expense {
        if ($total <= 0) {
            throw new RuntimeException('El monto del gasto debe ser mayor que cero.');
        }

        if ($tax < 0 || $tax > $total) {
            throw new RuntimeException('El impuesto no puede ser mayor que el total.');
        }

        return DB::transaction(function () use (
            $total, $description, $categoryId, $accountId, $supplierId,
            $expenseDate, $tax, $reference, $notes, $isRecurring, $branchId
        ) {
            // La cuenta se lee bloqueada: el saldo tiene que comprobarse
            // contra lo que hay ahora, no contra lo que habia cuando se
            // abrio la pantalla.
            $account = $accountId
                ? Account::with('currency')->lockForUpdate()->find($accountId)
                : null;

            if ($accountId !== null && $account === null) {
                throw new RuntimeException('Esa cuenta no existe.');
            }

            if ($account !== null && $total > $account->balance) {
                throw new RuntimeException("No hay tanto saldo en {$account->name}.");
            }

            $rate = (float) ($account?->currency?->rate ?? 1);

            $expense = Expense::create([
                'branch_id' => $branchId ?? auth()->user()->branch_id,
                'category_id' => $categoryId,
                'account_id' => $accountId,
                'supplier_id' => $supplierId,
                'user_id' => auth()->id(),
                'folio' => $this->nextFolio(),
                'expense_date' => $expenseDate ?? now()->toDateString(),
                'subtotal' => Pricing::round($total - $tax, 2),
                'tax' => Pricing::round($tax, 2),
                'total' => Pricing::round($total, 2),
                'exchange_rate' => $rate,
                'total_primary' => Pricing::round($total * $rate, 2),
                'description' => $description,
                'reference' => $reference,
                'notes' => $notes,
                'is_recurring' => $isRecurring,
                'status' => 'registered',
            ]);

            if ($account !== null) {
                $this->treasury->move(
                    account: $account,
                    direction: 'out',
                    amount: $total,
                    description: $description,
                    source: 'expense',
                    sourceId: $expense->id,
                    reference: $reference,
                );
            }

            return $expense;
        });
    }

    /**
     * Anula un gasto y devuelve el dinero a la cuenta.
     * El gasto no se borra: queda marcado con su motivo.
     */
    public function cancel(Expense $expense, string $reason): Expense
    {
        if ($expense->isCancelled()) {
            throw new RuntimeException('Este gasto ya estaba anulado.');
        }

        return DB::transaction(function () use ($expense, $reason) {
            if ($expense->account) {
                $this->treasury->move(
                    account: $expense->account,
                    direction: 'in',
                    amount: (float) $expense->total,
                    description: "Anulacion del gasto {$expense->folio}: {$reason}",
                    source: 'expense',
                    sourceId: $expense->id,
                );
            }

            $expense->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $expense->fresh();
        });
    }

    /**
     * Repite un gasto recurrente con la fecha de hoy.
     * Es lo que evita volver a capturar la renta cada mes.
     */
    public function repeat(Expense $expense): Expense
    {
        return $this->register(
            total: (float) $expense->total,
            description: $expense->description,
            categoryId: $expense->category_id,
            accountId: $expense->account_id,
            supplierId: $expense->supplier_id,
            expenseDate: now()->toDateString(),
            tax: (float) $expense->tax,
            notes: $expense->notes,
            isRecurring: true,
            branchId: $expense->branch_id,
        );
    }

    protected function nextFolio(): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => auth()->user()->branch_id, 'doc_type' => 'expense'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'G-'],
        );

        return $series->nextFolio();
    }
}
