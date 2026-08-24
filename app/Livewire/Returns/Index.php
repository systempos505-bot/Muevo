<?php

namespace App\Livewire\Returns;

use App\Livewire\Page;
use App\Models\CreditNote;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Devoluciones emitidas: que volvio, cuanto se regreso y por que. */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('sales.view'), 403);

        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'from', 'to']);
        $this->resetPage();
    }

    protected function baseQuery(): Builder
    {
        return CreditNote::query()
            ->registered()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('folio', 'like', "%{$this->search}%")
                ->orWhere('reason', 'like', "%{$this->search}%")
                ->orWhereHas('sale', fn (Builder $s) => $s->where('folio', 'like', "%{$this->search}%"))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->when($this->type, fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to));
    }

    #[Computed]
    public function summary(): array
    {
        $row = $this->baseQuery()
            ->selectRaw("count(*) as notes,
                         coalesce(sum(total), 0) as total,
                         coalesce(sum(case when type = 'refund' then total else 0 end), 0) as refunded")
            ->first();

        return [
            'notes' => (int) $row->notes,
            'total' => (float) $row->total,
            'refunded' => (float) $row->refunded,
        ];
    }

    public function render()
    {
        return view('livewire.returns.index', [
            'notes' => $this->baseQuery()
                ->with(['sale', 'customer', 'user'])
                ->orderByDesc('created_at')
                ->paginate(25),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
