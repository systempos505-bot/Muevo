<?php

namespace App\Services;

use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inventario fisico: contar lo que de verdad hay y dejar la existencia
 * como quedo la cuenta.
 *
 * Abrir un conteo no mueve nada: solo deja una foto de lo que el sistema
 * decia en ese momento, para poder comparar contra lo que se va contando.
 * Aplicar es lo unico que de verdad cambia la existencia, y solo por las
 * lineas que quedaron distintas.
 */
class StockCountManager
{
    public function __construct(protected InventoryManager $inventory) {}

    /**
     * Abre un conteo y lo puebla con el catalogo activo de la sucursal.
     *
     * Se incluye todo, tenga o no existencia: un sobrante en un producto
     * que el sistema cree en cero solo se detecta si esa linea esta en el
     * conteo desde el principio.
     */
    public function open(string $branchId, ?string $notes = null, ?string $categoryId = null): StockCount
    {
        return DB::transaction(function () use ($branchId, $notes, $categoryId) {
            $count = StockCount::create([
                'branch_id' => $branchId,
                'folio' => $this->nextFolio($branchId),
                'notes' => $notes,
                'status' => StockCount::OPEN,
                'created_by' => auth()->id(),
            ]);

            $products = Product::query()
                ->active()
                ->where('track_stock', true)
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->leftJoin('inventories', fn ($join) => $join
                    ->on('inventories.product_id', '=', 'products.id')
                    ->where('inventories.branch_id', $branchId))
                ->orderBy('products.name')
                ->get([
                    'products.id', 'products.name', 'products.sku',
                    DB::raw('coalesce(inventories.quantity, 0) as current_qty'),
                ]);

            foreach ($products as $product) {
                $qty = (float) $product->current_qty;

                StockCountItem::create([
                    'count_id' => $count->id,
                    'product_id' => $product->id,
                    // Se arranca con la misma cantidad que decia el
                    // sistema: una linea que nadie toca no genera ajuste.
                    'system_qty' => $qty,
                    'counted_qty' => $qty,
                ]);
            }

            return $count->load('items.product');
        });
    }

    /**
     * Suma un producto puntual al conteo.
     *
     * Sirve para lo que un conteo filtrado por categoria dejo fuera, o
     * para algo que aparecio en el piso y no debia estar ahi.
     */
    public function addProduct(StockCount $count, string $productId): StockCountItem
    {
        $this->assertOpen($count);

        $existing = $count->items()->where('product_id', $productId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $product = Product::findOrFail($productId);

        if (! $product->track_stock) {
            throw new RuntimeException("\"{$product->name}\" no maneja stock: no hay nada que contar.");
        }

        $qty = (float) ($product->stock($count->branch_id));

        return StockCountItem::create([
            'count_id' => $count->id,
            'product_id' => $product->id,
            'system_qty' => $qty,
            'counted_qty' => $qty,
        ]);
    }

    /**
     * Guarda lo que se lleva contado hasta ahora, sin mover inventario.
     *
     * Un conteo fisico puede tomar horas y lo hace mas de una persona; si
     * no se guardara hasta el final, recargar la pantalla a medio contar
     * perderia todo el avance.
     *
     * @param  array<string, float>  $countedByItemId
     */
    public function saveProgress(StockCount $count, array $countedByItemId): void
    {
        $this->assertOpen($count);

        if ($countedByItemId === []) {
            return;
        }

        DB::transaction(function () use ($count, $countedByItemId) {
            foreach ($count->items()->whereKey(array_keys($countedByItemId))->get() as $item) {
                $item->update([
                    'counted_qty' => Pricing::round((float) $countedByItemId[$item->id], 3),
                ]);
            }
        });
    }

    /**
     * Convierte el conteo en movimientos de verdad.
     *
     * Cada linea se aplica con InventoryManager::setQuantity(), que
     * calcula la diferencia contra la existencia actual y no contra la
     * que habia al abrir el conteo: si algo se vendio mientras se
     * contaba, el ajuste no lo pisa ni lo duplica.
     */
    public function apply(StockCount $count): StockCount
    {
        $this->assertOpen($count);

        return DB::transaction(function () use ($count) {
            $count->load('items.product');

            foreach ($count->items as $item) {
                if ($item->product === null || ! $item->product->track_stock) {
                    continue;
                }

                $this->inventory->setQuantity(
                    product: $item->product,
                    branchId: $count->branch_id,
                    countedQuantity: (float) $item->counted_qty,
                    reason: "Inventario fisico {$count->folio}",
                    variantId: $item->variant_id,
                    referenceType: 'stock_count',
                    referenceId: $count->id,
                );
            }

            $count->update([
                'status' => StockCount::APPLIED,
                'applied_by' => auth()->id(),
                'applied_at' => now(),
            ]);

            return $count->fresh('items');
        });
    }

    /**
     * Cierra el conteo sin aplicar nada.
     *
     * No mueve mercancia ni dinero, asi que a diferencia de anular una
     * venta o cancelar un traspaso no hace falta dejar un motivo escrito.
     */
    public function cancel(StockCount $count): StockCount
    {
        $this->assertOpen($count);

        $count->update(['status' => StockCount::CANCELLED]);

        return $count->fresh();
    }

    protected function assertOpen(StockCount $count): void
    {
        if ($count->isApplied()) {
            throw new RuntimeException('Este conteo ya se aplico.');
        }

        if ($count->isCancelled()) {
            throw new RuntimeException('Este conteo esta cancelado.');
        }
    }

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'adjustment'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'INV-'],
        );

        return $series->nextFolio();
    }
}
