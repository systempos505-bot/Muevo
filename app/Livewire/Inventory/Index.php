<?php

namespace App\Livewire\Inventory;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Inventory as InventoryModel;
use App\Models\Product;
use App\Services\InventoryManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Existencias por sucursal, con ajustes y conteo fisico.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $branchId = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    // --- Ajuste ---
    public bool $showAdjust = false;

    public ?string $adjustProductId = null;

    public string $adjustProductName = '';

    public float $adjustCurrent = 0;

    /** 'delta' suma o resta; 'set' deja la existencia en una cantidad exacta. */
    public string $adjustMode = 'delta';

    public ?float $adjustQuantity = null;

    public string $adjustType = 'adjustment';

    public string $adjustReason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);

        $this->branchId = $this->branchId ?: (string) (auth()->user()->branch_id ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'branchId', 'filter'], true)) {
            $this->resetPage();
        }
    }

    // =========================================================
    // Ajuste
    // =========================================================

    public function openAdjust(string $productId, ?string $branchId = null): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $product = Product::findOrFail($productId);

        $this->adjustProductId = $product->id;
        $this->adjustProductName = $product->name;
        $this->branchId = $branchId ?: $this->branchId ?: (string) Branch::active()->value('id');
        $this->adjustCurrent = (float) (InventoryModel::where('product_id', $product->id)
            ->where('branch_id', $this->branchId)
            ->whereNull('variant_id')
            ->value('quantity') ?? 0);

        $this->reset(['adjustQuantity', 'adjustReason']);
        $this->adjustMode = 'delta';
        $this->adjustType = 'adjustment';
        $this->resetValidation();
        $this->showAdjust = true;
    }

    public function saveAdjust(InventoryManager $inventory): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $this->validate([
            'adjustQuantity' => ['required', 'numeric'],
            'adjustReason' => ['required', 'string', 'min:3', 'max:300'],
            'adjustMode' => ['required', Rule::in(['delta', 'set'])],
            'adjustType' => ['required', Rule::in(array_keys(InventoryManager::MANUAL_TYPES))],
        ], [
            'adjustReason.required' => 'Escribe el motivo del ajuste.',
            'adjustReason.min' => 'El motivo debe explicar el ajuste.',
            'adjustQuantity.required' => 'Indica la cantidad.',
        ]);

        $product = Product::findOrFail($this->adjustProductId);

        try {
            if ($this->adjustMode === 'set') {
                $result = $inventory->setQuantity(
                    product: $product,
                    branchId: $this->branchId,
                    countedQuantity: (float) $this->adjustQuantity,
                    reason: $this->adjustReason,
                );

                if ($result === null) {
                    $this->notify('La cantidad contada ya coincide con el sistema.');
                    $this->showAdjust = false;

                    return;
                }
            } else {
                $inventory->move(
                    product: $product,
                    branchId: $this->branchId,
                    quantity: (float) $this->adjustQuantity,
                    type: $this->adjustType,
                    reason: $this->adjustReason,
                );
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('adjustQuantity', $e->getMessage());

            return;
        }

        $this->showAdjust = false;
        $this->reset(['adjustProductId', 'adjustQuantity', 'adjustReason']);
        $this->notify('Inventario ajustado');
    }

    public function render()
    {
        $rows = InventoryModel::query()
            ->with(['product.baseUnit', 'product.category', 'branch', 'variant'])
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->where('products.status', 'active')
            ->where('products.track_stock', true)
            ->when($this->branchId, fn (Builder $q) => $q->where('inventories.branch_id', $this->branchId))
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('products.name', 'like', "%{$this->search}%")
                ->orWhere('products.sku', 'like', "%{$this->search}%")))
            ->when($this->filter === 'low', fn (Builder $q) => $q
                ->where('inventories.quantity', '>', 0)
                ->whereColumn('inventories.quantity', '<=', 'products.min_stock')
                ->where('products.min_stock', '>', 0))
            ->when($this->filter === 'out', fn (Builder $q) => $q->where('inventories.quantity', '<=', 0))
            ->select('inventories.*')
            ->orderBy('products.name')
            ->paginate(25);

        return view('livewire.inventory.index', [
            'rows' => $rows,
            'branches' => Branch::active()->orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
            'adjustTypes' => InventoryManager::MANUAL_TYPES,
        ]);
    }
}
