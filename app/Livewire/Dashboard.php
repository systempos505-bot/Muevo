<?php

namespace App\Livewire;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductLot;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Panel de inicio.
 *
 * Por ahora muestra el estado del catalogo y las alertas de inventario.
 * Las cifras de ventas y ganancia llegan con el modulo de ventas.
 */
#[Layout('layouts.app')]
class Dashboard extends Page
{
    #[Computed]
    public function stats(): array
    {
        return [
            'products' => Product::active()->count(),
            'lowStock' => Inventory::query()
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('products.status', 'active')
                ->where('products.track_stock', true)
                ->where('products.min_stock', '>', 0)
                ->whereColumn('inventories.quantity', '<=', 'products.min_stock')
                ->where('inventories.quantity', '>', 0)
                ->count(),
            'outOfStock' => Inventory::query()
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('products.status', 'active')
                ->where('products.track_stock', true)
                ->where('inventories.quantity', '<=', 0)
                ->count(),
            'expiring' => ProductLot::query()
                ->join('products', 'products.id', '=', 'product_lots.product_id')
                ->sellable()
                ->whereNotNull('expiry_date')
                ->withinAlertWindow()
                ->count(),
        ];
    }

    #[Computed]
    public function expiringLots()
    {
        return ProductLot::with(['product', 'branch'])
            ->sellable()
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(60))
            ->fefo()
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function lowStockItems()
    {
        return Inventory::with(['product.baseUnit', 'branch'])
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->where('products.status', 'active')
            ->where('products.track_stock', true)
            ->where('products.min_stock', '>', 0)
            ->whereColumn('inventories.quantity', '<=', 'products.min_stock')
            ->select('inventories.*')
            ->orderBy('inventories.quantity')
            ->limit(8)
            ->get();
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
