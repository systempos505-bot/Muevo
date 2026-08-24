<?php

namespace App\Livewire\Purchases;

use App\Livewire\Page;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Historial de compras con su estado de pago. */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $supplierId = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('purchases.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'supplierId', 'filter'], true)) {
            $this->resetPage();
        }
    }

    protected function baseQuery(): Builder
    {
        return Purchase::query()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('folio', 'like', "%{$this->search}%")
                ->orWhere('invoice_number', 'like', "%{$this->search}%")))
            ->when($this->supplierId, fn (Builder $q) => $q->where('supplier_id', $this->supplierId))
            ->when($this->filter === 'pending', fn (Builder $q) => $q
                ->where('status', 'received')
                ->whereColumn('paid', '<', 'total'))
            ->when($this->filter === 'overdue', fn (Builder $q) => $q
                ->where('status', 'received')
                ->where('payment_type', 'credit')
                ->whereColumn('paid', '<', 'total')
                ->whereDate('due_date', '<', now()))
            ->when($this->filter === 'cancelled', fn (Builder $q) => $q->where('status', 'cancelled'))
            ->when($this->filter === 'all', fn (Builder $q) => $q->where('status', 'received'));
    }

    #[Computed]
    public function summary(): array
    {
        $row = $this->baseQuery()
            ->selectRaw('count(*) as purchases, coalesce(sum(total), 0) as total,
                         coalesce(sum(total - paid), 0) as pending')
            ->first();

        return [
            'purchases' => (int) $row->purchases,
            'total' => (float) $row->total,
            'pending' => (float) $row->pending,
        ];
    }

    public function render()
    {
        return view('livewire.purchases.index', [
            'purchases' => $this->baseQuery()
                ->with(['supplier', 'user'])
                ->latest()
                ->paginate(25),
            'suppliers' => Supplier::orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
