<?php

namespace App\Livewire\Pos;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\CashRegister;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/**
 * Caja y turnos: apertura, movimientos de efectivo y corte.
 */
#[Layout('layouts.app')]
class CashDrawer extends Page
{
    // --- Apertura ---
    public bool $showOpen = false;

    public ?float $openingAmount = null;

    // --- Movimiento de efectivo ---
    public bool $showMovement = false;

    public string $movementType = 'out';

    public ?float $movementAmount = null;

    public string $movementReason = '';

    // --- Cierre ---
    public bool $showClose = false;

    public ?float $countedAmount = null;

    public string $closeNotes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('cash.open'), 403);
    }

    #[Computed]
    public function terminal(): ?Terminal
    {
        return Terminal::where('branch_id', auth()->user()->branch_id)
            ->where('status', 'active')
            ->first()
            ?? Terminal::where('status', 'active')->first();
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return $this->terminal ? Shift::openFor($this->terminal->id) : null;
    }

    /** Cifras del turno abierto, todas calculadas en el modelo. */
    #[Computed]
    public function summary(): ?array
    {
        $shift = $this->shift;

        if ($shift === null) {
            return null;
        }

        return [
            'opening' => $shift->opening_amount,
            'cash_sales' => $shift->cashSales(),
            'change' => $shift->changeGiven(),
            'cash_in' => $shift->cashIn(),
            'cash_out' => $shift->cashOut(),
            'expected' => $shift->expectedCash(),
            'sales_total' => $shift->salesTotal(),
            'sales_count' => $shift->salesCount(),
        ];
    }

    // =========================================================
    // Acciones
    // =========================================================

    public function open(CashRegister $cash): void
    {
        abort_unless(auth()->user()->can('cash.open'), 403);

        $this->validate(
            ['openingAmount' => ['required', 'numeric', 'min:0']],
            ['openingAmount.required' => 'Indica con cuanto efectivo abres la caja.'],
        );

        try {
            $cash->open(
                $this->terminal->id,
                auth()->user()->branch_id ?? Branch::active()->value('id'),
                (float) $this->openingAmount,
            );
        } catch (RuntimeException $e) {
            $this->addError('openingAmount', $e->getMessage());

            return;
        }

        unset($this->shift, $this->summary);
        $this->showOpen = false;
        $this->openingAmount = null;
        $this->notify('Caja abierta');
    }

    public function saveMovement(CashRegister $cash): void
    {
        abort_unless(auth()->user()->can('cash.open'), 403);

        $this->validate([
            'movementAmount' => ['required', 'numeric', 'gt:0'],
            'movementReason' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'movementAmount.gt' => 'El monto debe ser mayor que cero.',
            'movementReason.required' => 'Escribe el motivo del movimiento.',
        ]);

        try {
            $cash->move(
                $this->shift,
                $this->movementType,
                (float) $this->movementAmount,
                $this->movementReason,
            );
        } catch (RuntimeException $e) {
            $this->addError('movementAmount', $e->getMessage());

            return;
        }

        unset($this->summary);
        $this->showMovement = false;
        $this->reset(['movementAmount', 'movementReason']);
        $this->notify('Movimiento registrado');
    }

    public function close(CashRegister $cash): void
    {
        abort_unless(auth()->user()->can('cash.close'), 403);

        $this->validate(
            ['countedAmount' => ['required', 'numeric', 'min:0']],
            ['countedAmount.required' => 'Cuenta el efectivo antes de cerrar.'],
        );

        try {
            $cash->close($this->shift, (float) $this->countedAmount, $this->closeNotes ?: null);
        } catch (RuntimeException $e) {
            $this->addError('countedAmount', $e->getMessage());

            return;
        }

        unset($this->shift, $this->summary);
        $this->showClose = false;
        $this->reset(['countedAmount', 'closeNotes']);
        $this->notify('Caja cerrada');
    }

    public function render()
    {
        return view('livewire.pos.cash-drawer', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'movements' => $this->shift?->cashMovements()->with('user')->latest()->get() ?? collect(),
            'recentShifts' => Shift::with(['user', 'terminal'])
                ->where('status', 'closed')
                ->latest('closed_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
