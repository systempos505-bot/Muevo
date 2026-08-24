<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\DocumentSeries;
use App\Models\Shift;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura, movimientos y cierre de turno de caja.
 */
class CashRegister
{
    /**
     * Abre un turno en una caja.
     *
     * Una caja no puede tener dos turnos abiertos: si no, dos cajeros
     * cuadrarian contra el mismo efectivo y ninguno sabria de quien es la
     * diferencia.
     */
    public function open(string $terminalId, string $branchId, float $openingAmount, ?string $notes = null): Shift
    {
        if ($openingAmount < 0) {
            throw new RuntimeException('El fondo inicial no puede ser negativo.');
        }

        return DB::transaction(function () use ($terminalId, $branchId, $openingAmount, $notes) {
            if (Shift::openFor($terminalId) !== null) {
                throw new RuntimeException('Esta caja ya tiene un turno abierto.');
            }

            return Shift::create([
                'branch_id' => $branchId,
                'terminal_id' => $terminalId,
                'user_id' => auth()->id(),
                'folio' => $this->nextFolio($branchId),
                'opened_at' => now(),
                'opening_amount' => $openingAmount,
                'notes' => $notes,
                'status' => 'open',
            ]);
        });
    }

    /**
     * Cierra el turno guardando el arqueo.
     *
     * La diferencia se guarda tal cual, sin corregirla: si falta dinero,
     * el sistema tiene que decirlo, no taparlo.
     */
    public function close(Shift $shift, float $countedAmount, ?string $notes = null): Shift
    {
        if (! $shift->isOpen()) {
            throw new RuntimeException('Este turno ya esta cerrado.');
        }

        $expected = $shift->expectedCash();

        $shift->update([
            'closed_at' => now(),
            'counted_amount' => $countedAmount,
            'expected_amount' => $expected,
            'difference' => Pricing::round($countedAmount - $expected, 2),
            'notes' => $notes ?? $shift->notes,
            'status' => 'closed',
        ]);

        return $shift->fresh();
    }

    /** Retiro o ingreso de efectivo durante el turno. */
    public function move(Shift $shift, string $type, float $amount, string $reason): CashMovement
    {
        if (! $shift->isOpen()) {
            throw new RuntimeException('No se puede mover efectivo en un turno cerrado.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('El monto debe ser mayor que cero.');
        }

        if (! in_array($type, ['in', 'out'], true)) {
            throw new RuntimeException('Tipo de movimiento invalido.');
        }

        // Un retiro mayor que lo que hay en el cajon deja la caja en
        // negativo, que siempre es un error de captura.
        if ($type === 'out' && $amount > $shift->expectedCash()) {
            throw new RuntimeException('No hay tanto efectivo en la caja.');
        }

        return CashMovement::create([
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'shift'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'T-', 'padding' => 5],
        );

        return $series->nextFolio();
    }
}
