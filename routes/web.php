<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Catalog;
use App\Livewire\Dashboard;
use App\Livewire\Finance;
use App\Livewire\Inventory;
use App\Livewire\Partners;
use App\Livewire\Pos;
use App\Livewire\Products;
use App\Livewire\Purchases;
use App\Livewire\Reports;
use App\Livewire\Sales;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', Login::class)->name('login');
    Route::get('/registro', Register::class)->name('register');
});

Route::post('/salir', function () {
    auth()->logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/panel', Dashboard::class)->name('dashboard');

    // --- Productos ---
    Route::get('/productos', Products\Index::class)->name('products');
    Route::get('/productos/nuevo', Products\Form::class)->name('products.create');
    Route::get('/productos/{productId}', Products\Form::class)->name('products.edit');

    // --- Catalogo de apoyo ---
    Route::prefix('catalogo')->name('catalog.')->group(function () {
        Route::get('/categorias', Catalog\Categories::class)->name('categories');
        Route::get('/marcas', Catalog\Brands::class)->name('brands');
        Route::get('/unidades', Catalog\Units::class)->name('units');
        Route::get('/listas-de-precios', Catalog\PriceLists::class)->name('price-lists');
    });

    // --- Punto de venta ---
    Route::get('/vender', Pos\Register::class)->name('pos');
    Route::get('/caja', Pos\CashDrawer::class)->name('cash');

    // --- Ventas ---
    Route::get('/ventas', Sales\Index::class)->name('sales');
    Route::get('/ventas/{saleId}', Sales\Show::class)->name('sales.show');

    // --- Compras y proveedores ---
    Route::get('/compras', Purchases\Index::class)->name('purchases');
    Route::get('/compras/nueva', Purchases\Form::class)->name('purchases.create');
    Route::get('/compras/{purchaseId}', Purchases\Show::class)->name('purchases.show');
    Route::get('/proveedores', Partners\Suppliers::class)->name('suppliers');

    // --- Clientes ---
    Route::get('/clientes', Partners\Customers::class)->name('customers');
    Route::get('/clientes/{customerId}', Partners\CustomerShow::class)->name('customers.show');

    // --- Finanzas ---
    Route::get('/cuentas', Finance\Accounts::class)->name('accounts');
    Route::get('/cuentas/{accountId}', Finance\AccountShow::class)->name('accounts.show');
    Route::get('/gastos', Finance\Expenses::class)->name('expenses');

    // --- Reportes ---
    Route::get('/reportes', Reports\Index::class)->name('reports');

    // --- Inventario ---
    Route::get('/inventario', Inventory\Index::class)->name('inventory');
    Route::get('/inventario/{productId}/kardex', Inventory\Kardex::class)->name('inventory.kardex');
});
