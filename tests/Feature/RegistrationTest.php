<?php

use App\Livewire\Auth\Register;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Livewire;

it('registra una empresa y la deja lista para operar', function () {
    Livewire::test(Register::class)
        ->set('businessName', 'Farmacia La Salud')
        ->set('ownerName', 'Maria Lopez')
        ->set('email', 'maria@lasalud.test')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'clave-segura-1')
        ->set('businessType', 'pharmacy')
        ->set('currencyCode', 'HNL')
        ->set('currencySymbol', 'L')
        ->set('taxRate', 15)
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $tenant = Tenant::firstWhere('email', 'maria@lasalud.test');

    expect($tenant)->not->toBeNull()
        ->and($tenant->business_type)->toBe('pharmacy')
        ->and($tenant->status)->toBe('trial');

    Tenancy::set($tenant->id);

    // Una empresa recien creada tiene que poder vender de inmediato:
    // sucursal, caja, moneda, impuesto, unidades y listas de precios.
    expect($tenant->branches()->count())->toBe(1)
        ->and($tenant->primaryCurrency->code)->toBe('HNL')
        ->and(Unit::count())->toBe(8)
        ->and(PriceList::count())->toBe(3)
        ->and(PriceList::where('is_default', true)->value('name'))->toBe('Publico')
        // Las categorias corresponden al giro elegido.
        ->and(Category::pluck('name')->all())->toContain('Medicamentos');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('maria@lasalud.test')
        ->and(auth()->user()->can('products.create'))->toBeTrue();
});

it('rechaza un correo ya registrado', function () {
    Livewire::test(Register::class)
        ->set('businessName', 'Negocio Uno')
        ->set('ownerName', 'Ana')
        ->set('email', 'repetido@test.com')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'clave-segura-1')
        ->call('register');

    auth()->logout();
    Tenancy::forget();

    Livewire::test(Register::class)
        ->set('businessName', 'Negocio Dos')
        ->set('ownerName', 'Luis')
        ->set('email', 'repetido@test.com')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'clave-segura-1')
        ->call('register')
        ->assertHasErrors(['email' => 'unique']);

    expect(Tenant::count())->toBe(1);
});

it('exige que las contrasenas coincidan', function () {
    Livewire::test(Register::class)
        ->set('businessName', 'Negocio')
        ->set('ownerName', 'Ana')
        ->set('email', 'ana@test.com')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'otra-clave-2')
        ->call('register')
        ->assertHasErrors('password');

    expect(User::count())->toBe(0);
});

it('guarda la contrasena hasheada, nunca en texto plano', function () {
    Livewire::test(Register::class)
        ->set('businessName', 'Negocio')
        ->set('ownerName', 'Ana')
        ->set('email', 'ana@test.com')
        ->set('password', 'clave-segura-1')
        ->set('password_confirmation', 'clave-segura-1')
        ->call('register');

    $user = User::allTenants()->first();

    expect($user->password)->not->toBe('clave-segura-1')
        ->and(Hash::check('clave-segura-1', $user->password))->toBeTrue();
});
