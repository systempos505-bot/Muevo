<?php

namespace App\Livewire\Purchases;

use App\Livewire\Page;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\PurchaseRegistrar;
use Livewire\Attributes\Layout;
use RuntimeException;

/** Detalle de una compra, con abonos y anulacion. */
#[Layout('layouts.app')]
class Show extends Page
{
    public Purchase $purchase;

    public bool $showPayment = false;

    public ?float $paymentAmount = null;

    public ?string $paymentMethodId = null;

    public string $paymentReference = '';

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(string $purchaseId): void
    {
        abort_unless(auth()->user()->can('purchases.view'), 403);

        $this->purchase = Purchase::with(['items.product', 'supplier', 'user', 'branch', 'payments'])
            ->findOrFail($purchaseId);

        $this->paymentMethodId = PaymentMethod::active()->where('type', 'cash')->value('id');
        $this->paymentAmount = $this->purchase->balance();
    }

    public function pay(PurchaseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('purchases.create'), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
        ], ['paymentAmount.gt' => 'El abono debe ser mayor que cero.']);

        try {
            $registrar->pay(
                $this->purchase,
                (float) $this->paymentAmount,
                $this->paymentMethodId,
                $this->paymentReference ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->purchase->refresh();
        $this->showPayment = false;
        $this->paymentAmount = $this->purchase->balance();
        $this->paymentReference = '';
        $this->notify('Abono registrado');
    }

    public function cancel(PurchaseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('purchases.void'), 403);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'min:5', 'max:300']],
            ['cancelReason.required' => 'Escribe por que se anula la compra.'],
        );

        try {
            $registrar->cancel($this->purchase, $this->cancelReason);
        } catch (RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->purchase->refresh();
        $this->showCancel = false;
        $this->notify('Compra anulada');
    }

    public function render()
    {
        return view('livewire.purchases.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'paymentMethods' => PaymentMethod::active()
                ->where('type', '!=', 'credit')
                ->orderBy('position')
                ->get(),
        ]);
    }
}
