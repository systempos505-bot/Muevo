<?php

use App\Livewire\Promotions\Index;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Unit;
use App\Services\CashRegister;
use App\Services\InventoryManager;
use App\Services\SaleRegistrar;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->branchId = $this->context['setup']['branch']->id;

    $this->category = Category::create(['name' => 'Bebidas']);

    $this->product = Product::create([
        'sku' => 'B-1',
        'name' => 'Refresco de litro',
        'category_id' => $this->category->id,
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);
});

/** Rellena el formulario con lo minimo de un 2x1. */
function fillNxm($component, string $name = '2x1 en refrescos')
{
    return $component
        ->set('name', $name)
        ->set('type', 'nxm')
        ->set('buyQuantity', 2)
        ->set('getQuantity', 1);
}

describe('acceso', function () {
    it('niega la pantalla a quien no tiene el permiso', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'promotions.view' => false],
        ]);

        $this->get(route('promotions'))->assertForbidden();
    });

    it('deja mirar pero no guardar a quien solo puede ver', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'promotions.view' => true],
        ]);

        $this->get(route('promotions'))->assertOk()->assertDontSee('+ Promocion');

        fillNxm(Livewire::test(Index::class))->call('save')->assertForbidden();
    });
});

describe('alta', function () {
    it('crea un 2x1 que aplica a todo el catalogo', function () {
        fillNxm(Livewire::test(Index::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $promo = Promotion::first();

        expect($promo->name)->toBe('2x1 en refrescos')
            ->and($promo->applies_to_all)->toBeTrue()
            ->and($promo->badge())->toBe('2x1');
    });

    it('rechaza una promocion que regala mas de lo que se lleva', function () {
        // 2x3 no es una oferta, es un error de captura.
        Livewire::test(Index::class)
            ->set('name', 'Mal capturada')
            ->set('type', 'nxm')
            ->set('buyQuantity', 2)
            ->set('getQuantity', 3)
            ->call('save')
            ->assertHasErrors(['getQuantity']);
    });

    it('exige el dato propio de cada tipo', function () {
        Livewire::test(Index::class)
            ->set('name', 'Sin porcentaje')
            ->set('type', 'percent')
            ->call('save')
            ->assertHasErrors(['discountPercent']);

        Livewire::test(Index::class)
            ->set('name', 'Sin precio')
            ->set('type', 'bundle_price')
            ->set('buyQuantity', 3)
            ->call('save')
            ->assertHasErrors(['bundlePrice']);
    });

    it('no deja terminar antes de empezar', function () {
        fillNxm(Livewire::test(Index::class))
            ->set('startsOn', now()->toDateString())
            ->set('endsOn', now()->subDays(3)->toDateString())
            ->call('save')
            ->assertHasErrors(['endsOn']);
    });

    it('pide la hora de fin si se puso la de inicio', function () {
        fillNxm(Livewire::test(Index::class))
            ->set('startsAt', '14:00')
            ->call('save')
            ->assertHasErrors(['endsAt']);
    });

    it('exige elegir algo si no aplica a todo', function () {
        fillNxm(Livewire::test(Index::class))
            ->set('appliesToAll', false)
            ->call('save')
            ->assertHasErrors(['targets']);
    });

    it('guarda a que categoria aplica', function () {
        fillNxm(Livewire::test(Index::class))
            ->set('appliesToAll', false)
            ->set('targetType', 'category')
            ->call('addTarget', $this->category->id, 'Bebidas')
            ->call('save')
            ->assertHasNoErrors();

        $target = PromotionTarget::first();

        expect($target->target_type)->toBe('category')
            ->and($target->target_id)->toBe($this->category->id);
    });

    it('guarda como todos los dias cuando se marcan los siete', function () {
        // Marcar los siete y no marcar ninguno significan lo mismo; se
        // guarda de una sola forma para no tener que comparar dos.
        fillNxm(Livewire::test(Index::class))
            ->set('weekdays', [1, 2, 3, 4, 5, 6, 7])
            ->call('save');

        expect(Promotion::first()->weekdays)->toBeNull();
    });

    it('guarda los dias elegidos', function () {
        fillNxm(Livewire::test(Index::class))
            ->set('weekdays', [2, 4])
            ->call('save');

        expect(Promotion::first()->weekdays)->toBe([2, 4]);
    });
});

describe('busqueda de lo que alcanza', function () {
    it('no busca con menos de dos letras', function () {
        $component = Livewire::test(Index::class)
            ->set('appliesToAll', false)
            ->set('targetSearch', 'R');

        expect($component->instance()->targetResults)->toHaveCount(0);
    });

    it('encuentra productos por nombre', function () {
        $component = Livewire::test(Index::class)
            ->set('appliesToAll', false)
            ->set('targetSearch', 'Refresco');

        expect($component->instance()->targetResults->pluck('name')->all())
            ->toBe(['Refresco de litro']);
    });

    it('no vuelve a ofrecer lo ya elegido', function () {
        $component = Livewire::test(Index::class)
            ->set('appliesToAll', false)
            ->call('addTarget', $this->product->id, 'Refresco de litro')
            ->set('targetSearch', 'Refresco');

        expect($component->instance()->targetResults)->toHaveCount(0);
    });

    it('elegir algo deja de aplicar a todo el catalogo', function () {
        Livewire::test(Index::class)
            ->call('addTarget', $this->product->id, 'Refresco de litro')
            ->assertSet('appliesToAll', false);
    });
});

describe('edicion y estado', function () {
    it('carga la promocion en el formulario', function () {
        $promo = Promotion::create([
            'name' => 'Martes de 2x1',
            'type' => 'nxm',
            'applies_to_all' => true,
            'buy_quantity' => 3,
            'get_quantity' => 1,
            'weekdays' => [2],
        ]);

        Livewire::test(Index::class)
            ->call('edit', $promo->id)
            ->assertSet('name', 'Martes de 2x1')
            ->assertSet('buyQuantity', 3)
            ->assertSet('weekdays', [2])
            ->assertSet('showForm', true);
    });

    it('enciende y apaga', function () {
        $promo = Promotion::create([
            'name' => '2x1', 'type' => 'nxm', 'applies_to_all' => true,
            'buy_quantity' => 2, 'get_quantity' => 1,
        ]);

        Livewire::test(Index::class)->call('toggle', $promo->id);
        expect($promo->fresh()->status)->toBe('inactive');

        Livewire::test(Index::class)->call('toggle', $promo->id);
        expect($promo->fresh()->status)->toBe('active');
    });

    it('borra una promocion que nunca se uso', function () {
        $promo = Promotion::create([
            'name' => '2x1', 'type' => 'nxm', 'applies_to_all' => true,
            'buy_quantity' => 2, 'get_quantity' => 1,
        ]);

        Livewire::test(Index::class)->call('delete', $promo->id);

        expect(Promotion::count())->toBe(0);
    });

    it('apaga en lugar de borrar una promocion que ya se uso', function () {
        $promo = Promotion::create([
            'name' => '2x1', 'type' => 'nxm', 'applies_to_all' => true,
            'buy_quantity' => 2, 'get_quantity' => 1,
        ]);

        app(InventoryManager::class)->move($this->product, $this->branchId, 50, 'initial');
        $shift = app(CashRegister::class)->open(
            $this->context['setup']['terminal']->id, $this->branchId, 0,
        );

        app(SaleRegistrar::class)->register(
            shift: $shift,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 25]],
            payments: [['payment_method_id' => PaymentMethod::where('code', 'EFE')->value('id'), 'amount' => 25]],
        );

        Livewire::test(Index::class)->call('delete', $promo->id);

        // Borrarla dejaria tickets que no se pueden explicar.
        expect(Promotion::count())->toBe(1)
            ->and($promo->fresh()->status)->toBe('inactive');
    });
});

describe('listado', function () {
    it('cuenta las que estan corriendo ahora', function () {
        Promotion::create([
            'name' => 'Vigente', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
        ]);
        Promotion::create([
            'name' => 'Vencida', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        expect(Livewire::test(Index::class)->instance()->runningNow)->toBe(1);
    });

    it('filtra las vencidas', function () {
        Promotion::create([
            'name' => 'La que sigue corriendo', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
        ]);
        Promotion::create([
            'name' => 'La del mes pasado', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        Livewire::test(Index::class)
            ->set('status', 'expired')
            ->assertSee('La del mes pasado')
            ->assertDontSee('La que sigue corriendo');
    });

    it('busca por nombre', function () {
        Promotion::create([
            'name' => 'Martes de refrescos', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
        ]);
        Promotion::create([
            'name' => 'Fin de semana', 'type' => 'percent',
            'applies_to_all' => true, 'discount_percent' => 10,
        ]);

        Livewire::test(Index::class)
            ->set('search', 'Martes')
            ->assertSee('Martes de refrescos')
            ->assertDontSee('Fin de semana');
    });
});
