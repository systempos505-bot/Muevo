<?php

namespace App\Livewire\Sales;

use App\Livewire\Page;
use App\Models\CreditNote;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Services\ReturnRegistrar;
use App\Services\SaleRegistrar;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/**
 * Ticket de una venta, con la opcion de anularla o devolver parte.
 *
 * Ni anular ni devolver tocan la venta original: anular la marca y
 * deshace lo que hizo, y una devolucion emite su propio documento. Asi el
 * corte del dia en que se vendio sigue siendo el que fue.
 */
#[Layout('layouts.app')]
class Show extends Page
{
    public Sale $sale;

    public bool $showCancel = false;

    public string $cancelReason = '';

    // --- Devolucion ---
    public bool $showReturn = false;

    /** [sale_item_id => cantidad a devolver] */
    public array $returnLines = [];

    public string $returnReason = '';

    public string $returnType = CreditNote::REFUND;

    public string $returnMethodId = '';

    public bool $returnRestock = true;

    public function mount(string $saleId): void
    {
        abort_unless(auth()->user()->can('sales.view'), 403);

        $this->sale = Sale::with([
            'items.product', 'items.promotions', 'payments.paymentMethod',
            'customer', 'user', 'branch', 'terminal', 'creditNotes',
        ])->findOrFail($saleId);
    }

    public function cancel(SaleRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('sales.void'), 403);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'min:5', 'max:300']],
            ['cancelReason.required' => 'Escribe por que se anula la venta.'],
        );

        try {
            $registrar->cancel($this->sale, $this->cancelReason);
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->sale->refresh();
        $this->showCancel = false;
        $this->notify('Venta anulada');
    }

    // =========================================================
    // Devolucion
    // =========================================================

    /**
     * Cuanto queda por devolver de cada linea.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function returnable(): array
    {
        return $this->sale->items
            ->mapWithKeys(fn (SaleItem $item) => [$item->id => $item->returnableQuantity()])
            ->all();
    }

    public function openReturn(): void
    {
        abort_unless(auth()->user()->can('sales.return'), 403);

        $this->reset(['returnLines', 'returnReason', 'returnMethodId']);
        $this->returnRestock = true;

        // Una venta a credito se devuelve bajando la deuda, no sacando
        // dinero de una caja que nunca lo recibio.
        $onCredit = $this->sale->payments->contains(fn ($p) => $p->paymentMethod?->isCredit());
        $this->returnType = $onCredit && $this->sale->customer_id
            ? CreditNote::CREDIT
            : CreditNote::REFUND;

        $this->resetValidation();
        $this->showReturn = true;
    }

    /** Rellena el formulario con todo lo que queda pendiente. */
    public function returnAll(): void
    {
        $this->returnLines = $this->returnable;
    }

    public function saveReturn(ReturnRegistrar $returns): void
    {
        abort_unless(auth()->user()->can('sales.return'), 403);

        $this->validate(
            [
                'returnReason' => ['required', 'string', 'min:5', 'max:300'],
                'returnType' => ['required', Rule::in([CreditNote::REFUND, CreditNote::CREDIT])],
            ],
            ['returnReason.required' => 'Escribe por que se devuelve la mercancia.'],
        );

        $lines = collect($this->returnLines)
            ->map(fn ($quantity, $itemId) => [
                'sale_item_id' => $itemId,
                'quantity' => (float) $quantity,
            ])
            ->filter(fn (array $line) => $line['quantity'] > 0)
            ->values()
            ->all();

        if ($lines === []) {
            $this->addError('returnLines', 'Indica que se devuelve y en que cantidad.');

            return;
        }

        try {
            $note = $returns->register(
                sale: $this->sale,
                lines: $lines,
                reason: $this->returnReason,
                type: $this->returnType,
                paymentMethodId: $this->returnMethodId ?: null,
                restock: $this->returnRestock,
                shift: Shift::openFor($this->sale->terminal_id),
            );
        } catch (RuntimeException $e) {
            $this->addError('returnLines', $e->getMessage());

            return;
        }

        $this->showReturn = false;
        $this->sale->refresh()->load(['items.product', 'items.promotions', 'creditNotes']);
        unset($this->returnable);

        $this->notify("Devolucion {$note->folio} registrada");
        $this->redirectRoute('returns.show', $note->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.sales.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'tenant' => auth()->user()->tenant,
            'paymentMethods' => PaymentMethod::active()
                ->where('type', '!=', 'credit')
                ->orderBy('position')
                ->get(),
        ]);
    }
}
