<?php

namespace App\Livewire\Sales;

use App\Livewire\Page;
use App\Models\Sale;
use App\Services\InventoryManager;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;

/**
 * Ticket de una venta, con la opcion de anularla.
 *
 * Anular no borra: marca la venta y devuelve la mercancia al inventario
 * con su propio movimiento, para que el kardex explique por que volvio.
 */
#[Layout('layouts.app')]
class Show extends Page
{
    public Sale $sale;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(string $saleId): void
    {
        abort_unless(auth()->user()->can('sales.view'), 403);

        $this->sale = Sale::with(['items.product', 'items.promotions', 'payments', 'customer', 'user', 'branch', 'terminal'])
            ->findOrFail($saleId);
    }

    public function cancel(InventoryManager $inventory): void
    {
        abort_unless(auth()->user()->can('sales.void'), 403);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'min:5', 'max:300']],
            ['cancelReason.required' => 'Escribe por que se anula la venta.'],
        );

        if ($this->sale->isCancelled()) {
            $this->notify('Esta venta ya estaba anulada', 'error');

            return;
        }

        DB::transaction(function () use ($inventory) {
            foreach ($this->sale->items as $item) {
                if ($item->product === null || ! $item->product->track_stock) {
                    continue;
                }

                $inventory->move(
                    product: $item->product,
                    branchId: $this->sale->branch_id,
                    quantity: $item->base_quantity,
                    type: 'sale_return',
                    reason: "Anulacion de {$this->sale->folio}",
                    variantId: $item->variant_id,
                    referenceType: 'sale',
                    referenceId: $this->sale->id,
                );
            }

            // El credito que se le cargo al cliente se le devuelve.
            if ($this->sale->customer) {
                $credit = $this->sale->payments
                    ->filter(fn ($p) => $p->paymentMethod?->isCredit())
                    ->sum('amount_primary');

                if ($credit > 0) {
                    $this->sale->customer->decrement('balance', $credit);
                }
            }

            $this->sale->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $this->cancelReason,
            ]);
        });

        $this->sale->refresh();
        $this->showCancel = false;
        $this->notify('Venta anulada');
    }

    public function render()
    {
        return view('livewire.sales.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'tenant' => auth()->user()->tenant,
        ]);
    }
}
