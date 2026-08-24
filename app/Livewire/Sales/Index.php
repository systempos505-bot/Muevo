<?php

namespace App\Livewire\Sales;

use App\Livewire\Page;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Historial de ventas, con filtros por fecha, cajero y estado. */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $userId = '';

    #[Url(except: 'completed')]
    public string $status = 'completed';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('sales.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to', 'userId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'from', 'to', 'userId']);
        $this->resetPage();
    }

    protected function baseQuery(): Builder
    {
        return Sale::query()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('folio', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->userId, fn (Builder $q) => $q->where('user_id', $this->userId))
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status));
    }

    /** Resumen del periodo filtrado, no solo de la pagina visible. */
    #[Computed]
    public function summary(): array
    {
        $row = $this->baseQuery()
            ->selectRaw('count(*) as sales, coalesce(sum(total), 0) as total,
                         coalesce(sum(tax), 0) as tax, coalesce(sum(cost_total), 0) as cost')
            ->first();

        return [
            'sales' => (int) $row->sales,
            'total' => (float) $row->total,
            'profit' => round((float) $row->total - (float) $row->tax - (float) $row->cost, 2),
            'average' => $row->sales > 0 ? round((float) $row->total / (int) $row->sales, 2) : 0.0,
        ];
    }

    public function render()
    {
        return view('livewire.sales.index', [
            'sales' => $this->baseQuery()
                ->with(['customer', 'user'])
                ->latest()
                ->paginate(25),
            'users' => User::orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
