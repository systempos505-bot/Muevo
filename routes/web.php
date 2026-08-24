<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Catalog;
use App\Livewire\Dashboard;
use App\Livewire\Inventory;
use App\Livewire\Products;
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

    // --- Inventario ---
    Route::get('/inventario', Inventory\Index::class)->name('inventory');
    Route::get('/inventario/{productId}/kardex', Inventory\Kardex::class)->name('inventory.kardex');
});
