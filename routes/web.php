<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
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

    Route::get('/productos', Products\Index::class)->name('products');
    Route::get('/productos/nuevo', Products\Form::class)->name('products.create');
    Route::get('/productos/{productId}', Products\Form::class)->name('products.edit');
});
