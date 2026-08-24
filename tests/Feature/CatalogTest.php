<?php

use App\Livewire\Catalog\Brands;
use App\Livewire\Catalog\Categories;
use App\Livewire\Catalog\PriceLists as PriceListsScreen;
use App\Livewire\Catalog\Units;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Unit;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant('supermarket');
});

// =============================================================
// Categorias
// =============================================================

describe('categorias', function () {
    it('crea una categoria principal', function () {
        Livewire::test(Categories::class)
            ->call('create')
            ->set('name', 'Panaderia')
            ->call('save')
            ->assertHasNoErrors();

        expect(Category::where('name', 'Panaderia')->whereNull('parent_id')->exists())->toBeTrue();
    });

    it('crea una subcategoria colgando de una principal', function () {
        $parent = Category::where('name', 'Abarrotes')->first();

        Livewire::test(Categories::class)
            ->call('create', $parent->id)
            ->set('name', 'Enlatados')
            ->call('save')
            ->assertHasNoErrors();

        $child = Category::where('name', 'Enlatados')->first();

        expect($child->parent_id)->toBe($parent->id)
            ->and($child->fullName())->toBe('Abarrotes / Enlatados');
    });

    it('no permite tres niveles de profundidad', function () {
        $parent = Category::where('name', 'Abarrotes')->first();
        $child = Category::create(['name' => 'Enlatados', 'parent_id' => $parent->id]);

        Livewire::test(Categories::class)
            ->call('create', $child->id)
            ->set('name', 'Atun')
            ->call('save')
            ->assertHasErrors('parentId');

        expect(Category::where('name', 'Atun')->exists())->toBeFalse();
    });

    it('no deja que una categoria dependa de si misma', function () {
        $category = Category::where('name', 'Abarrotes')->first();

        Livewire::test(Categories::class)
            ->call('edit', $category->id)
            ->set('parentId', $category->id)
            ->call('save')
            ->assertHasErrors('parentId');
    });

    it('se niega a borrar una categoria con productos', function () {
        $category = Category::where('name', 'Abarrotes')->first();

        Product::create([
            'sku' => 'P-1',
            'name' => 'Arroz',
            'category_id' => $category->id,
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        Livewire::test(Categories::class)->call('delete', $category->id);

        // Borrar dejaria el producto sin categoria sin avisarle a nadie.
        expect(Category::find($category->id))->not->toBeNull();
    });

    it('se niega a borrar una categoria con subcategorias', function () {
        $parent = Category::where('name', 'Abarrotes')->first();
        Category::create(['name' => 'Enlatados', 'parent_id' => $parent->id]);

        Livewire::test(Categories::class)->call('delete', $parent->id);

        expect(Category::find($parent->id))->not->toBeNull();
    });

    it('borra una categoria vacia', function () {
        $category = Category::create(['name' => 'Temporal']);

        Livewire::test(Categories::class)->call('delete', $category->id);

        expect(Category::find($category->id))->toBeNull();
    });

    it('desactiva una categoria en lugar de borrarla', function () {
        $category = Category::where('name', 'Abarrotes')->first();

        Livewire::test(Categories::class)->call('toggleStatus', $category->id);

        expect($category->fresh()->status)->toBe('inactive');
    });
});

// =============================================================
// Marcas
// =============================================================

describe('marcas', function () {
    it('crea una marca', function () {
        Livewire::test(Brands::class)
            ->call('create')
            ->set('name', 'Bayer')
            ->call('save')
            ->assertHasNoErrors();

        expect(Brand::where('name', 'Bayer')->exists())->toBeTrue();
    });

    it('no acepta dos marcas con el mismo nombre', function () {
        Brand::create(['name' => 'Bayer']);

        Livewire::test(Brands::class)
            ->call('create')
            ->set('name', 'Bayer')
            ->call('save')
            ->assertHasErrors('name');

        expect(Brand::count())->toBe(1);
    });

    it('se niega a borrar una marca en uso', function () {
        $brand = Brand::create(['name' => 'Bayer']);

        Product::create([
            'sku' => 'P-1',
            'name' => 'Aspirina',
            'brand_id' => $brand->id,
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        Livewire::test(Brands::class)->call('delete', $brand->id);

        expect(Brand::find($brand->id))->not->toBeNull();
    });
});

// =============================================================
// Unidades
// =============================================================

describe('unidades', function () {
    it('crea una unidad y guarda el codigo en mayusculas', function () {
        Livewire::test(Units::class)
            ->call('create')
            ->set('code', 'gal')
            ->set('name', 'Galon')
            ->set('allowsDecimals', true)
            ->call('save')
            ->assertHasNoErrors();

        $unit = Unit::where('code', 'GAL')->first();

        expect($unit)->not->toBeNull()
            ->and($unit->allows_decimals)->toBeTrue();
    });

    it('no acepta dos unidades con el mismo codigo', function () {
        Livewire::test(Units::class)
            ->call('create')
            ->set('code', 'UND')
            ->set('name', 'Otra unidad')
            ->call('save')
            ->assertHasErrors('code');
    });

    it('se niega a borrar una unidad usada como unidad base', function () {
        $unit = Unit::where('code', 'UND')->first();

        Product::create([
            'sku' => 'P-1',
            'name' => 'Producto',
            'base_unit_id' => $unit->id,
        ]);

        Livewire::test(Units::class)->call('delete', $unit->id);

        expect(Unit::find($unit->id))->not->toBeNull();
    });
});

// =============================================================
// Listas de precios
// =============================================================

describe('listas de precios', function () {
    it('crea una lista que trabaja por margen', function () {
        Livewire::test(PriceListsScreen::class)
            ->call('create')
            ->set('name', 'Empleados')
            ->set('pricingMode', 'margin')
            ->set('marginPercent', 12)
            ->call('save')
            ->assertHasNoErrors();

        $list = PriceList::where('name', 'Empleados')->first();

        expect($list->usesMargin())->toBeTrue()
            ->and($list->margin_percent)->toBe(12.0);
    });

    it('exige el margen cuando la lista trabaja por margen', function () {
        Livewire::test(PriceListsScreen::class)
            ->call('create')
            ->set('name', 'Empleados')
            ->set('pricingMode', 'margin')
            ->set('marginPercent', null)
            ->call('save')
            ->assertHasErrors('marginPercent');
    });

    it('cambia cual es la lista de mostrador dejando solo una', function () {
        $mayoreo = PriceList::where('name', 'Mayoreo')->first();

        Livewire::test(PriceListsScreen::class)->call('makeDefault', $mayoreo->id);

        expect($mayoreo->fresh()->is_default)->toBeTrue()
            ->and(PriceList::where('is_default', true)->count())->toBe(1);
    });

    it('no deja desactivar la lista de mostrador', function () {
        $publico = PriceList::where('is_default', true)->first();

        Livewire::test(PriceListsScreen::class)->call('toggleStatus', $publico->id);

        // Sin lista de mostrador la caja se quedaria sin precio.
        expect($publico->fresh()->status)->toBe('active');
    });

    it('no deja borrar la lista de mostrador', function () {
        $publico = PriceList::where('is_default', true)->first();

        Livewire::test(PriceListsScreen::class)->call('delete', $publico->id);

        expect(PriceList::find($publico->id))->not->toBeNull();
    });

    it('se niega a borrar una lista con precios capturados', function () {
        $mayoreo = PriceList::where('name', 'Mayoreo')->first();

        $product = Product::create([
            'sku' => 'P-1',
            'name' => 'Producto',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'price_list_id' => $mayoreo->id,
            'min_quantity' => 1,
            'price' => 10,
        ]);

        Livewire::test(PriceListsScreen::class)->call('delete', $mayoreo->id);

        expect(PriceList::find($mayoreo->id))->not->toBeNull();
    });

    it('respeta el limite de diez listas', function () {
        // Ya vienen tres del aprovisionamiento inicial.
        for ($i = 4; $i <= 10; $i++) {
            PriceList::create(['name' => "Lista {$i}", 'position' => $i]);
        }

        Livewire::test(PriceListsScreen::class)
            ->call('create')
            ->assertSet('showForm', false);

        expect(PriceList::count())->toBe(10);
    });
});
