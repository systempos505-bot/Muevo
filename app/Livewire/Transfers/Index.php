<?php

namespace App\Livewire\Transfers;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\Pricing;
use App\Services\TransferManager;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Traspasos entre sucursales.
 *
 * El listado y el alta viven en la misma pantalla: armar un traspaso es
 * elegir dos sucursales y unos productos, y mandar a otra pantalla para
 * eso solo agrega pasos.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    // --- Alta ---
    public bool $showForm = false;

    public string $fromBranchId = '';

    public string $toBranchId = '';

    public string $notes = '';

    /** @var array<string, array{product_id: string, name: string, sku: ?string, unit: ?string, quantity: float}> */
    public array $lines = [];

    public string $productSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);

        $this->fromBranchId = (string) (auth()->user()->branch_id ?? Branch::active()->value('id'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    // =========================================================
    // Listado
    // =========================================================

    protected function baseQuery(): Builder
    {
        return StockTransfer::query()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('folio', 'like', "%{$this->search}%")
                ->orWhereHas('items', fn (Builder $i) => $i->where('description', 'like', "%{$this->search}%"))))
            ->when($this->status, fn (Builder $q) => $q->where('status', $this->status));
    }

    /** Cuantos traspasos estan esperando salir o llegar. */
    #[Computed]
    public function pending(): int
    {
        return StockTransfer::pending()->count();
    }

    /** Sucursales del negocio. Con una sola no hay a donde traspasar. */
    #[Computed]
    public function branches()
    {
        return Branch::active()->orderBy('name')->get();
    }

    // =========================================================
    // Alta
    // =========================================================

    public function create(): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        if ($this->branches->count() < 2) {
            $this->notify('Necesitas al menos dos sucursales para traspasar.', 'error');

            return;
        }

        $this->reset(['lines', 'notes', 'productSearch', 'toBranchId']);
        $this->fromBranchId = (string) (auth()->user()->branch_id ?? $this->branches->first()->id);
        $this->toBranchId = (string) $this->branches->firstWhere('id', '!=', $this->fromBranchId)?->id;

        $this->resetValidation();
        $this->showForm = true;
    }

    #[Computed]
    public function results()
    {
        $term = trim($this->productSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->with('baseUnit')
            ->active()
            ->where('track_stock', true)
            ->whereNotIn('id', array_column($this->lines, 'product_id'))
            ->search($term)
            ->limit(8)
            ->get();
    }

    /**
     * Existencia en el origen de cada producto del traspaso.
     *
     * Se muestra al lado de la cantidad para que nadie escriba de memoria
     * un numero que la tienda no tiene.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function availability(): array
    {
        if ($this->lines === [] || ! $this->fromBranchId) {
            return [];
        }

        return Inventory::where('branch_id', $this->fromBranchId)
            ->whereIn('product_id', array_column($this->lines, 'product_id'))
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    public function addProduct(string $productId): void
    {
        $product = Product::with('baseUnit')->find($productId);

        if ($product === null) {
            return;
        }

        $key = $product->id;

        if (isset($this->lines[$key])) {
            $this->lines[$key]['quantity'] = Pricing::round(
                (float) $this->lines[$key]['quantity'] + 1,
                3,
            );
        } else {
            $this->lines[$key] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->baseUnit?->name,
                'quantity' => 1.0,
            ];
        }

        $this->productSearch = '';
        unset($this->availability);
    }

    public function removeLine(string $key): void
    {
        unset($this->lines[$key]);
        unset($this->availability);
    }

    public function save(TransferManager $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $this->validate(
            [
                'fromBranchId' => ['required', 'different:toBranchId'],
                'toBranchId' => ['required'],
                'notes' => ['nullable', 'string', 'max:300'],
            ],
            [
                'fromBranchId.different' => 'El origen y el destino tienen que ser sucursales distintas.',
                'toBranchId.required' => 'Elige a que sucursal va.',
            ],
        );

        if ($this->lines === []) {
            $this->addError('lines', 'Agrega al menos un producto.');

            return;
        }

        try {
            $transfer = $transfers->create(
                fromBranchId: $this->fromBranchId,
                toBranchId: $this->toBranchId,
                lines: array_values($this->lines),
                notes: $this->notes ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->reset(['lines', 'notes', 'productSearch']);

        $this->notify("Traspaso {$transfer->folio} creado");
        $this->redirectRoute('transfers.show', $transfer->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.transfers.index', [
            'transfers' => $this->baseQuery()
                ->with(['fromBranch', 'toBranch', 'items'])
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }
}
