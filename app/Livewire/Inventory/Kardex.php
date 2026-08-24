<?php

namespace App\Livewire\Inventory;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Kardex: toda la historia de movimientos de un producto.
 *
 * Cada renglon trae el saldo que quedo despues del movimiento, asi que
 * se puede leer de corrido sin recalcular nada hacia atras.
 */
#[Layout('layouts.app')]
class Kardex extends Page
{
    use WithPagination;

    public Product $product;

    #[Url(except: '')]
    public string $branchId = '';

    #[Url(except: '')]
    public string $type = '';

    public function mount(string $productId): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);

        $this->product = Product::with('baseUnit')->findOrFail($productId);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['branchId', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $movements = InventoryMovement::query()
            ->with(['branch', 'user', 'variant', 'lot'])
            ->where('product_id', $this->product->id)
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30);

        $stock = $this->product->inventories()
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->sum('quantity');

        return view('livewire.inventory.kardex', [
            'movements' => $movements,
            'branches' => Branch::active()->orderBy('name')->get(),
            'stock' => (float) $stock,
            'currency' => auth()->user()->tenant->primaryCurrency,
            'types' => [
                'initial' => 'Inventario inicial',
                'purchase' => 'Compra',
                'sale' => 'Venta',
                'sale_return' => 'Devolucion de cliente',
                'adjustment' => 'Ajuste',
                'count' => 'Inventario fisico',
                'loss' => 'Merma',
                'transfer_in' => 'Traspaso entrada',
                'transfer_out' => 'Traspaso salida',
            ],
        ]);
    }
}
