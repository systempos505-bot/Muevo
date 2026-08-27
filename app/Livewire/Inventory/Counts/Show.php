<?php

namespace App\Livewire\Inventory\Counts;

use App\Livewire\Page;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\StockCountManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/**
 * Captura de un conteo: lo que decia el sistema contra lo que se conto.
 *
 * Guardar el avance y aplicar son dos pasos separados a proposito: un
 * conteo fisico toma horas y lo puede hacer mas de una persona, asi que
 * hay que poder salir y volver sin perder lo capturado y sin que la
 * existencia se mueva hasta que de verdad se decida aplicar.
 */
#[Layout('layouts.app')]
class Show extends Page
{
    public StockCount $count;

    /** [stock_count_item_id => cantidad contada, tal como se edita en pantalla] */
    public array $counted = [];

    public string $search = '';

    // --- Agregar producto ---
    public bool $showAddProduct = false;

    public string $productSearch = '';

    // --- Cancelacion ---
    public bool $showCancel = false;

    public function mount(string $countId): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);

        $this->loadCount($countId);
    }

    protected function loadCount(string $countId): void
    {
        $this->count = StockCount::with(['items.product.baseUnit', 'branch', 'creator', 'applier'])
            ->findOrFail($countId);

        $this->counted = $this->count->items
            ->mapWithKeys(fn (StockCountItem $item) => [$item->id => $item->counted_qty])
            ->all();
    }

    /**
     * Lineas que coinciden con la busqueda.
     *
     * Se filtra en la coleccion ya cargada y no con una consulta nueva:
     * el conteo entero ya esta en pantalla, y volver a pedirlo al
     * servidor en cada letra solo lo haria mas lento.
     */
    #[Computed]
    public function visibleItems()
    {
        $term = mb_strtolower(trim($this->search));

        if ($term === '') {
            return $this->count->items;
        }

        return $this->count->items->filter(
            fn (StockCountItem $item) => str_contains(mb_strtolower($item->product?->name ?? ''), $term)
                || str_contains(mb_strtolower((string) $item->product?->sku), $term),
        );
    }

    /**
     * Resumen del avance, calculado sobre lo que hay en pantalla, no
     * sobre lo que ya se guardo: el usuario necesita ver el efecto de
     * lo que esta escribiendo antes de decidir guardarlo.
     *
     * @return array{lines: int, differences: int, shortage: float, overage: float}
     */
    #[Computed]
    public function summary(): array
    {
        $shortage = 0.0;
        $overage = 0.0;
        $differences = 0;

        foreach ($this->count->items as $item) {
            $diff = round((float) ($this->counted[$item->id] ?? $item->counted_qty) - $item->system_qty, 3);

            if ($diff == 0.0) {
                continue;
            }

            $differences++;
            $value = abs($diff) * (float) ($item->product?->cost ?? 0);

            if ($diff > 0) {
                $overage += $value;
            } else {
                $shortage += $value;
            }
        }

        return [
            'lines' => $this->count->items->count(),
            'differences' => $differences,
            'shortage' => round($shortage, 2),
            'overage' => round($overage, 2),
        ];
    }

    public function saveProgress(StockCountManager $counts): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        $this->run($counts, fn () => $counts->saveProgress(
            $this->count,
            collect($this->counted)->map(fn ($qty) => (float) $qty)->all(),
        ), 'Avance guardado');
    }

    public function apply(StockCountManager $counts): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        $this->run($counts, function () use ($counts) {
            // Se guarda lo que este en pantalla antes de aplicar: aplicar
            // sin guardar dejaria fuera lo ultimo que se conto.
            $counts->saveProgress(
                $this->count,
                collect($this->counted)->map(fn ($qty) => (float) $qty)->all(),
            );

            $counts->apply($this->count);
        }, 'Conteo aplicado');
    }

    public function cancel(StockCountManager $counts): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        $this->run($counts, fn () => $counts->cancel($this->count), 'Conteo cancelado');
        $this->showCancel = false;
    }

    // =========================================================
    // Sumar un producto puntual
    // =========================================================

    #[Computed]
    public function productResults()
    {
        $term = trim($this->productSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $already = $this->count->items->pluck('product_id');

        return Product::query()
            ->active()
            ->where('track_stock', true)
            ->whereNotIn('id', $already)
            ->search($term)
            ->limit(8)
            ->get();
    }

    public function addProduct(string $productId, StockCountManager $counts): void
    {
        abort_unless(auth()->user()->can('inventory.count'), 403);

        try {
            $counts->addProduct($this->count, $productId);
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->loadCount($this->count->id);
        $this->productSearch = '';
        unset($this->visibleItems, $this->summary);
    }

    /**
     * Corre una accion del conteo y refresca la pantalla.
     *
     * Los errores de negocio se muestran tal cual: estan escritos para
     * que quien esta contando sepa que hacer.
     */
    protected function run(StockCountManager $counts, callable $action, string $message): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->loadCount($this->count->id);
        unset($this->visibleItems, $this->summary);

        $this->notify($message);
    }

    public function render()
    {
        return view('livewire.inventory.counts.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
