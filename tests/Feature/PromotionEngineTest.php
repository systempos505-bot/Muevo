<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Unit;
use App\Services\PromotionEngine;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->engine = app(PromotionEngine::class);

    $this->category = Category::create(['name' => 'Bebidas']);
    $this->brand = Brand::create(['name' => 'Refresca']);

    $this->product = Product::create([
        'sku' => 'B-1',
        'name' => 'Refresco de litro',
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        'cost' => 10,
    ]);
});

/** Crea una promocion y, si se le dice, la apunta a algo. */
function promo(array $attributes, ?array $target = null): Promotion
{
    $promotion = Promotion::create([
        'name' => $attributes['name'] ?? 'Promocion',
        'applies_to_all' => $target === null,
        ...$attributes,
    ]);

    if ($target !== null) {
        PromotionTarget::create([
            'promotion_id' => $promotion->id,
            'target_type' => $target[0],
            'target_id' => $target[1],
        ]);
    }

    return $promotion->load('targets');
}

// =============================================================
// Calculo por tipo
// =============================================================

describe('lleva N paga M', function () {
    it('regala una de cada dos en un 2x1', function () {
        $p = promo(['type' => 'nxm', 'buy_quantity' => 2, 'get_quantity' => 1]);

        expect($this->engine->calculate($p, 2, 20))
            ->toBe(['discount' => 20.0, 'free_quantity' => 1.0]);
    });

    it('no aplica si no se alcanza el paquete', function () {
        $p = promo(['type' => 'nxm', 'buy_quantity' => 2, 'get_quantity' => 1]);

        expect($this->engine->calculate($p, 1, 20)['discount'])->toBe(0.0);
    });

    it('cobra normal lo que sobra del paquete', function () {
        // 3x2: llevar 7 paga 5, no 4.66.
        $p = promo(['type' => 'nxm', 'buy_quantity' => 3, 'get_quantity' => 1]);

        $result = $this->engine->calculate($p, 7, 30);

        expect($result['free_quantity'])->toBe(2.0)
            ->and($result['discount'])->toBe(60.0);
    });

    it('se repite todas las veces que quepa', function () {
        $p = promo(['type' => 'nxm', 'buy_quantity' => 2, 'get_quantity' => 1]);

        expect($this->engine->calculate($p, 20, 20)['free_quantity'])->toBe(10.0);
    });

    it('respeta el tope de repeticiones por linea', function () {
        $p = promo([
            'type' => 'nxm', 'buy_quantity' => 2, 'get_quantity' => 1,
            'max_uses_per_line' => 3,
        ]);

        // Llevar 20 daria 10 gratis, pero el tope deja 3.
        expect($this->engine->calculate($p, 20, 20)['free_quantity'])->toBe(3.0);
    });

    it('ignora una promocion mal capturada que regala de mas', function () {
        // 2x3 regalaria mas de lo que se lleva.
        $p = promo(['type' => 'nxm', 'buy_quantity' => 2, 'get_quantity' => 3]);

        expect($this->engine->calculate($p, 10, 20)['discount'])->toBe(0.0);
    });
});

describe('porcentaje y monto', function () {
    it('descuenta el porcentaje de la linea', function () {
        $p = promo(['type' => 'percent', 'discount_percent' => 10]);

        expect($this->engine->calculate($p, 3, 50)['discount'])->toBe(15.0);
    });

    it('exige el minimo de unidades', function () {
        $p = promo(['type' => 'percent', 'discount_percent' => 10, 'min_quantity' => 5]);

        expect($this->engine->calculate($p, 4, 50)['discount'])->toBe(0.0)
            ->and($this->engine->calculate($p, 5, 50)['discount'])->toBe(25.0);
    });

    it('descuenta un monto por unidad', function () {
        $p = promo(['type' => 'amount', 'discount_amount' => 5]);

        expect($this->engine->calculate($p, 4, 50)['discount'])->toBe(20.0);
    });

    it('no descuenta mas que el precio del producto', function () {
        // Un descuento de 80 sobre algo de 50 dejaria la linea negativa.
        $p = promo(['type' => 'amount', 'discount_amount' => 80]);

        expect($this->engine->calculate($p, 2, 50)['discount'])->toBe(100.0);
    });
});

describe('precio de paquete', function () {
    it('cobra el paquete completo a su precio', function () {
        // 3 por 100 cuando cada uno vale 40: se ahorra 20.
        $p = promo(['type' => 'bundle_price', 'buy_quantity' => 3, 'bundle_price' => 100]);

        expect($this->engine->calculate($p, 3, 40)['discount'])->toBe(20.0);
    });

    it('cobra suelto lo que no completa un paquete', function () {
        $p = promo(['type' => 'bundle_price', 'buy_quantity' => 3, 'bundle_price' => 100]);

        expect($this->engine->calculate($p, 7, 40)['discount'])->toBe(40.0);
    });

    it('no aplica si el paquete sale mas caro', function () {
        // Una promocion que encarece es un error de captura.
        $p = promo(['type' => 'bundle_price', 'buy_quantity' => 3, 'bundle_price' => 150]);

        expect($this->engine->calculate($p, 3, 40)['discount'])->toBe(0.0);
    });
});

// =============================================================
// A que alcanza
// =============================================================

describe('alcance', function () {
    it('alcanza a todo el catalogo cuando asi se marca', function () {
        $p = promo(['type' => 'percent', 'discount_percent' => 10]);

        expect($this->engine->reaches($p, $this->product))->toBeTrue();
    });

    it('alcanza por producto, categoria o marca', function () {
        expect($this->engine->reaches(
            promo(['type' => 'percent', 'discount_percent' => 10], ['product', $this->product->id]),
            $this->product,
        ))->toBeTrue();

        expect($this->engine->reaches(
            promo(['type' => 'percent', 'discount_percent' => 10], ['category', $this->category->id]),
            $this->product,
        ))->toBeTrue();

        expect($this->engine->reaches(
            promo(['type' => 'percent', 'discount_percent' => 10], ['brand', $this->brand->id]),
            $this->product,
        ))->toBeTrue();
    });

    it('no alcanza a lo que no apunta', function () {
        $otro = Product::create([
            'sku' => 'B-2',
            'name' => 'Agua',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        $p = promo(['type' => 'percent', 'discount_percent' => 10], ['product', $this->product->id]);

        expect($this->engine->reaches($p, $otro))->toBeFalse();
    });
});

// =============================================================
// Vigencia
// =============================================================

describe('vigencia', function () {
    it('una promocion sin fechas corre siempre', function () {
        expect(promo(['type' => 'percent', 'discount_percent' => 10])->runsAt())->toBeTrue();
    });

    it('no corre antes de empezar ni despues de terminar', function () {
        $p = promo([
            'type' => 'percent', 'discount_percent' => 10,
            'starts_on' => now()->addDay()->toDateString(),
        ]);

        expect($p->runsAt())->toBeFalse();

        $vencida = promo([
            'type' => 'percent', 'discount_percent' => 10,
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        expect($vencida->runsAt())->toBeFalse()
            ->and($vencida->hasExpired())->toBeTrue();
    });

    it('corre el ultimo dia completo', function () {
        // Terminar "hoy" significa hasta las 23:59, no hasta las 00:00.
        $p = promo([
            'type' => 'percent', 'discount_percent' => 10,
            'ends_on' => now()->toDateString(),
        ]);

        expect($p->runsAt(now()->endOfDay()))->toBeTrue();
    });

    it('respeta los dias de la semana', function () {
        $hoy = now()->isoWeekday();
        $manana = now()->addDay()->isoWeekday();

        expect(promo([
            'type' => 'percent', 'discount_percent' => 10, 'weekdays' => [$hoy],
        ])->runsAt())->toBeTrue();

        expect(promo([
            'type' => 'percent', 'discount_percent' => 10, 'weekdays' => [$manana],
        ])->runsAt())->toBeFalse();
    });

    it('respeta la franja horaria', function () {
        $p = promo([
            'type' => 'percent', 'discount_percent' => 10,
            'starts_at' => '14:00:00', 'ends_at' => '18:00:00',
        ]);

        expect($p->runsAt(now()->setTime(15, 0)))->toBeTrue()
            ->and($p->runsAt(now()->setTime(19, 0)))->toBeFalse();
    });

    it('admite una franja que cruza la medianoche', function () {
        // El turno de noche de una gasolinera o una farmacia de guardia.
        $p = promo([
            'type' => 'percent', 'discount_percent' => 10,
            'starts_at' => '22:00:00', 'ends_at' => '02:00:00',
        ]);

        expect($p->runsAt(now()->setTime(23, 30)))->toBeTrue()
            ->and($p->runsAt(now()->setTime(1, 0)))->toBeTrue()
            ->and($p->runsAt(now()->setTime(12, 0)))->toBeFalse();
    });

    it('una promocion apagada no corre', function () {
        expect(promo([
            'type' => 'percent', 'discount_percent' => 10, 'status' => 'inactive',
        ])->runsAt())->toBeFalse();
    });
});

describe('promociones vigentes', function () {
    it('deja fuera las apagadas y las vencidas', function () {
        promo(['name' => 'Vigente', 'type' => 'percent', 'discount_percent' => 10]);
        promo(['name' => 'Apagada', 'type' => 'percent', 'discount_percent' => 10, 'status' => 'inactive']);
        promo([
            'name' => 'Vencida', 'type' => 'percent', 'discount_percent' => 10,
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        expect($this->engine->active()->pluck('name')->all())->toBe(['Vigente']);
    });

    it('una promocion sin sucursal corre en todas', function () {
        promo(['name' => 'Nacional', 'type' => 'percent', 'discount_percent' => 10]);

        $otra = Branch::create(['name' => 'Sucursal norte', 'code' => 'NOR']);

        expect($this->engine->active(branchId: $otra->id)->pluck('name')->all())
            ->toBe(['Nacional']);
    });

    it('una promocion de una sucursal no corre en otra', function () {
        $mia = $this->context['setup']['branch'];
        $otra = Branch::create(['name' => 'Sucursal norte', 'code' => 'NOR']);

        promo([
            'name' => 'Solo centro', 'type' => 'percent',
            'discount_percent' => 10, 'branch_id' => $mia->id,
        ]);

        expect($this->engine->active(branchId: $mia->id))->toHaveCount(1)
            ->and($this->engine->active(branchId: $otra->id))->toHaveCount(0);
    });

    it('una promocion de una lista de precios no corre en otra', function () {
        $mayoreo = PriceList::where('name', 'Mayoreo')->firstOrFail();

        promo([
            'name' => 'Solo mayoreo', 'type' => 'percent',
            'discount_percent' => 10, 'price_list_id' => $mayoreo->id,
        ]);

        expect($this->engine->active(priceListId: $mayoreo->id))->toHaveCount(1)
            ->and($this->engine->active(priceListId: null))->toHaveCount(0);
    });
});

// =============================================================
// Cual gana
// =============================================================

describe('cual gana', function () {
    it('gana la de mayor prioridad', function () {
        promo(['name' => 'Chica', 'type' => 'percent', 'discount_percent' => 50, 'priority' => 0]);
        promo(['name' => 'Grande', 'type' => 'percent', 'discount_percent' => 10, 'priority' => 5]);

        $result = $this->engine->forLine($this->product, 2, 100, $this->engine->active());

        // La prioridad manda sobre el importe: el negocio decidio cual va.
        expect($result['discount'])->toBe(20.0)
            ->and($result['applied'][0]['label'])->toBe('Grande');
    });

    it('a igual prioridad gana la que mas ahorra', function () {
        promo(['name' => 'Diez', 'type' => 'percent', 'discount_percent' => 10]);
        promo(['name' => 'Veinte', 'type' => 'percent', 'discount_percent' => 20]);

        $result = $this->engine->forLine($this->product, 1, 100, $this->engine->active());

        expect($result['applied'][0]['label'])->toBe('Veinte')
            ->and($result['discount'])->toBe(20.0);
    });

    it('aplica una sola cuando no son combinables', function () {
        promo(['name' => 'Una', 'type' => 'percent', 'discount_percent' => 10]);
        promo(['name' => 'Otra', 'type' => 'amount', 'discount_amount' => 5]);

        $result = $this->engine->forLine($this->product, 1, 100, $this->engine->active());

        expect($result['applied'])->toHaveCount(1);
    });

    it('suma las combinables entre si', function () {
        promo(['name' => 'Una', 'type' => 'percent', 'discount_percent' => 10, 'combinable' => true]);
        promo(['name' => 'Otra', 'type' => 'percent', 'discount_percent' => 5, 'combinable' => true]);

        $result = $this->engine->forLine($this->product, 1, 100, $this->engine->active());

        expect($result['applied'])->toHaveCount(2)
            ->and($result['discount'])->toBe(15.0);
    });

    it('no suma una combinable a una que no lo es', function () {
        promo(['name' => 'Exclusiva', 'type' => 'percent', 'discount_percent' => 30, 'priority' => 9]);
        promo(['name' => 'Sumable', 'type' => 'percent', 'discount_percent' => 10, 'combinable' => true]);

        $result = $this->engine->forLine($this->product, 1, 100, $this->engine->active());

        expect($result['applied'])->toHaveCount(1)
            ->and($result['discount'])->toBe(30.0);
    });

    it('nunca descuenta mas que la linea', function () {
        promo(['name' => 'Mitad', 'type' => 'percent', 'discount_percent' => 60, 'combinable' => true]);
        promo(['name' => 'Otra mitad', 'type' => 'percent', 'discount_percent' => 70, 'combinable' => true]);

        $result = $this->engine->forLine($this->product, 1, 100, $this->engine->active());

        // 130% de descuento dejaria la linea en negativo.
        expect($result['discount'])->toBe(100.0);
    });

    it('devuelve cero cuando ninguna alcanza al producto', function () {
        $otro = Product::create([
            'sku' => 'B-9',
            'name' => 'Pan',
            'base_unit_id' => Unit::where('code', 'UND')->value('id'),
        ]);

        promo(['type' => 'percent', 'discount_percent' => 10], ['product', $this->product->id]);

        $result = $this->engine->forLine($otro, 3, 100, $this->engine->active());

        expect($result['discount'])->toBe(0.0)
            ->and($result['applied'])->toBe([]);
    });
});
