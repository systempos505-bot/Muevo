<?php

namespace App\Livewire\Finance;

use App\Livewire\Page;
use App\Models\Account;
use App\Models\AccountMovement;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Historial de movimientos de una cuenta. */
#[Layout('layouts.app')]
class AccountShow extends Page
{
    use WithPagination;

    public Account $account;

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(string $accountId): void
    {
        abort_unless(auth()->user()->can('finance.view'), 403);

        $this->account = Account::with('currency')->findOrFail($accountId);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $query = AccountMovement::query()
            ->where('account_id', $this->account->id)
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to));

        $totals = (clone $query)
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else 0 end), 0) as total_in,
                         coalesce(sum(case when direction = 'out' then amount else 0 end), 0) as total_out")
            ->first();

        return view('livewire.finance.account-show', [
            'movements' => $query->with('user')->latest('id')->paginate(30),
            'totalIn' => (float) $totals->total_in,
            'totalOut' => (float) $totals->total_out,
            'sources' => [
                'sale' => 'Ventas',
                'purchase' => 'Compras',
                'expense' => 'Gastos',
                'customer_payment' => 'Abonos de clientes',
                'supplier_payment' => 'Pagos a proveedores',
                'transfer' => 'Traslados',
                'manual' => 'Manuales',
            ],
        ]);
    }
}
