<?php

namespace App\Livewire\Inventory\Counts;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Category;
use App\Models\StockCount;
use App\Services\StockCountManager;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Inventarios fisicos: listado y alta.
 *
 * Abrir uno es elegir sucursal y, si hace falta, una categoria para no
 * contar la tienda entera de una vez. El detalle de captura vive en su
 * propia pantalla, porque un conteo puede tener cientos de lineas.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    // --- Alta ---
    public bool $showForm = false;

    public string $branchId = '';

    public string $categoryId = '';

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);
    }

    public function updated(string $property): void
    {
        if ($property === 'status') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function branches()
    {
        return Branch::active()->orderBy('name')->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('name')->get();
    }

    public function create(): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        $this->reset(['categoryId', 'notes']);
        $this->branchId = (string) (auth()->user()->branch_id ?? $this->branches->first()?->id);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(StockCountManager $counts): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        $this->validate([
            'branchId' => ['required'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $count = $counts->open(
            branchId: $this->branchId,
            notes: $this->notes ?: null,
            categoryId: $this->categoryId ?: null,
        );

        $this->showForm = false;
        $this->reset(['categoryId', 'notes']);

        $this->notify("Conteo {$count->folio} abierto con {$count->items->count()} producto(s)");
        $this->redirectRoute('stock-counts.show', $count->id, navigate: true);
    }

    public function render()
    {
        $counts = StockCount::query()
            ->with(['branch', 'creator'])
            ->withCount('items')
            ->when($this->status, fn (Builder $q) => $q->where('status', $this->status))
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.inventory.counts.index', ['counts' => $counts]);
    }
}
