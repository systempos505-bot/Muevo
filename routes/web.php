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
use App\Livewire\Promotions;
use App\Livewire\Purchases;
use App\Livewire\Reports;
use App\Livewire\Returns;
use App\Livewire\Sales;
use App\Livewire\Settings;
use App\Livewire\Transfers;
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

    // --- Devoluciones ---
    Route::get('/devoluciones', Returns\Index::class)->name('returns');
    Route::get('/devoluciones/{noteId}', Returns\Show::class)->name('returns.show');

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

    // --- Promociones ---
    Route::get('/promociones', Promotions\Index::class)->name('promotions');

    // --- Reportes ---
    Route::get('/reportes', Reports\Index::class)->name('reports');

    // --- Inventario ---
    Route::get('/inventario', Inventory\Index::class)->name('inventory');
    Route::get('/inventario/{productId}/kardex', Inventory\Kardex::class)->name('inventory.kardex');

    // --- Configuracion ---
    Route::get('/sucursales', Settings\Branches::class)->name('branches');
    Route::get('/usuarios', Settings\Users::class)->name('users');
    Route::get('/roles', Settings\Roles::class)->name('roles');

    // --- Traspasos entre sucursales ---
    Route::get('/traspasos', Transfers\Index::class)->name('transfers');
    Route::get('/traspasos/{transferId}', Transfers\Show::class)->name('transfers.show');
});
