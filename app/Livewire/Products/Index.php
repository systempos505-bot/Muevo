<?php

namespace App\Livewire\Products;

use App\Livewire\Page;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Listado del catalogo, con busqueda y filtros.
 *
 * En escritorio se muestra como tabla y en celular como tarjetas: la
 * misma informacion, ordenada segun el ancho disponible.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $categoryId = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: 'all')]
    public string $stockFilter = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);
    }

    /** Al cambiar un filtro hay que volver a la primera pagina. */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'categoryId', 'status', 'stockFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryId', 'stockFilter']);
        $this->resetPage();
    }

    public function toggleStatus(string $productId): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $product = Product::findOrFail($productId);

        // Desactivar es reversible; borrar no, y ademas romperia las ventas
        // que ya referencian el producto.
        $product->update([
            'status' => $product->status === 'active' ? 'inactive' : 'active',
        ]);

        $this->notify(
            $product->status === 'active' ? 'Producto activado' : 'Producto desactivado',
        );
    }

    public function render()
    {
        $defaultList = PriceList::active()->where('is_default', true)->first();

        $products = Product::query()
            ->with(['category', 'brand', 'baseUnit', 'tax'])
            ->withSum('inventories as stock', 'quantity')
            ->withCount('variants')
            // El precio de mostrador se trae en la misma consulta para no
            // disparar una consulta por fila al pintar la tabla.
            ->when($defaultList, fn (Builder $q) => $q->addSelect([
                'default_price' => ProductPrice::select('price')
                    ->whereColumn('product_id', 'products.id')
                    ->where('price_list_id', $defaultList->id)
                    ->whereNull('variant_id')
                    ->orderBy('min_quantity')
                    ->limit(1),
            ]))
            ->search($this->search)
            ->when($this->categoryId, fn (Builder $q) => $q->where('category_id', $this->categoryId))
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->stockFilter === 'low', fn (Builder $q) => $q
                ->where('track_stock', true)
                ->where('min_stock', '>', 0)
                ->havingRaw('coalesce(stock, 0) <= products.min_stock'))
            ->when($this->stockFilter === 'out', fn (Builder $q) => $q
                ->where('track_stock', true)
                ->havingRaw('coalesce(stock, 0) <= 0'))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.products.index', [
            'products' => $products,
            'categories' => Category::active()->orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
