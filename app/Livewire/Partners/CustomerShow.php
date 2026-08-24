<?php

namespace App\Livewire\Partners;

use App\Livewire\Page;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\CustomerAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/**
 * Ficha del cliente: su cuenta, su estado de cuenta y sus compras.
 */
#[Layout('layouts.app')]
class CustomerShow extends Page
{
    public Customer $customer;

    public bool $showPayment = false;

    public ?float $paymentAmount = null;

    public ?string $paymentMethodId = null;

    public string $paymentReference = '';

    public function mount(string $customerId): void
    {
        abort_unless(auth()->user()->can('customers.view'), 403);

        $this->customer = Customer::with(['customerType', 'priceList'])->findOrFail($customerId);
        $this->paymentAmount = (float) $this->customer->balance;
    }

    #[Computed]
    public function account(): CustomerAccount
    {
        return app(CustomerAccount::class);
    }

    #[Computed]
    public function statement(): array
    {
        return $this->account->statement($this->customer);
    }

    #[Computed]
    public function available(): ?float
    {
        return $this->account->availableCredit($this->customer);
    }

    /** Ultimas compras, sean de contado o a credito. */
    #[Computed]
    public function sales()
    {
        return Sale::where('customer_id', $this->customer->id)
            ->latest()
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $row = Sale::where('customer_id', $this->customer->id)
            ->where('status', 'completed')
            ->selectRaw('count(*) as sales, coalesce(sum(total), 0) as total')
            ->first();

        return [
            'sales' => (int) $row->sales,
            'total' => (float) $row->total,
            'average' => $row->sales > 0 ? round((float) $row->total / (int) $row->sales, 2) : 0.0,
        ];
    }

    public function openPayment(): void
    {
        abort_unless(auth()->user()->can('customers.edit'), 403);

        if ($this->customer->balance <= 0) {
            $this->notify('Este cliente no debe nada', 'error');

            return;
        }

        $this->paymentAmount = (float) $this->customer->balance;
        $this->paymentMethodId = $this->account->paymentMethods()->first()?->id;
        $this->resetValidation();
        $this->showPayment = true;
    }

    public function pay(): void
    {
        abort_unless(auth()->user()->can('customers.edit'), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
        ], ['paymentAmount.gt' => 'El abono debe ser mayor que cero.']);

        // Si hay turno abierto, el abono en efectivo entra al cajon y
        // tiene que contar en el arqueo del cierre.
        $terminalId = Terminal::where('branch_id', auth()->user()->branch_id)
            ->where('status', 'active')
            ->value('id')
            ?? Terminal::where('status', 'active')->value('id');

        try {
            $this->account->receivePayment(
                customer: $this->customer,
                amount: (float) $this->paymentAmount,
                paymentMethodId: $this->paymentMethodId,
                shift: $terminalId ? Shift::openFor($terminalId) : null,
                reference: $this->paymentReference ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->customer->refresh();
        unset($this->statement, $this->available);

        $this->showPayment = false;
        $this->paymentAmount = (float) $this->customer->balance;
        $this->paymentReference = '';
        $this->notify('Abono registrado');
    }

    public function render()
    {
        return view('livewire.partners.customer-show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'paymentMethods' => $this->account->paymentMethods(),
            'payments' => CustomerPayment::with(['paymentMethod', 'user'])
                ->where('customer_id', $this->customer->id)
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }
}
