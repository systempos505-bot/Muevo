<?php

use App\Livewire\Settings\Branches;
use App\Models\Branch;
use App\Models\DocumentSeries;
use App\Models\Terminal;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->principal = $this->context['setup']['branch'];
});

describe('acceso', function () {
    it('niega la pantalla a quien no puede ver la configuracion', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'settings.view' => false],
        ]);

        $this->get(route('branches'))->assertForbidden();
    });

    it('deja mirar pero no guardar a quien solo puede ver', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'settings.view' => true],
        ]);

        $this->get(route('branches'))->assertOk()->assertDontSee('+ Sucursal');

        Livewire::test(Branches::class)
            ->set('code', 'NOR')
            ->set('name', 'Sucursal norte')
            ->call('save')
            ->assertForbidden();
    });
});

describe('alta', function () {
    it('crea la sucursal con su caja y sus series', function () {
        Livewire::test(Branches::class)
            ->call('create')
            ->set('code', 'NORTE')
            ->set('name', 'Sucursal norte')
            ->call('save')
            ->assertHasNoErrors();

        $branch = Branch::where('code', 'NORTE')->first();

        // Una sucursal sin caja ni series existe pero no sirve: no se
        // puede abrir turno, y sin turno no se puede vender.
        expect($branch)->not->toBeNull()
            ->and(Terminal::where('branch_id', $branch->id)->count())->toBe(1)
            ->and(DocumentSeries::where('branch_id', $branch->id)->count())->toBeGreaterThan(1);
    });

    it('le da a cada caja su propio prefijo de folios', function () {
        Livewire::test(Branches::class)
            ->call('create')
            ->set('code', 'NORTE')
            ->set('name', 'Sucursal norte')
            ->call('save');

        $prefixes = Terminal::pluck('folio_prefix');

        // Dos cajas con el mismo prefijo generarian el mismo numero de
        // venta en tiendas distintas.
        expect($prefixes->unique())->toHaveCount($prefixes->count());
    });

    it('no deja repetir el codigo', function () {
        Livewire::test(Branches::class)
            ->call('create')
            ->set('code', $this->principal->code)
            ->set('name', 'Otra con el mismo codigo')
            ->call('save')
            ->assertHasErrors(['code']);
    });

    it('rechaza un codigo con espacios', function () {
        Livewire::test(Branches::class)
            ->call('create')
            ->set('code', 'sucursal norte')
            ->set('name', 'Sucursal norte')
            ->call('save')
            ->assertHasErrors(['code']);
    });

    it('edita sin volver a crear caja', function () {
        Livewire::test(Branches::class)
            ->call('edit', $this->principal->id)
            ->set('name', 'Tienda del centro')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->principal->fresh()->name)->toBe('Tienda del centro')
            ->and(Terminal::where('branch_id', $this->principal->id)->count())->toBe(1);
    });
});

describe('estado', function () {
    it('cambia cual es la principal y deja solo una', function () {
        $norte = Branch::create(['code' => 'NOR', 'name' => 'Sucursal norte']);

        Livewire::test(Branches::class)->call('makeDefault', $norte->id);

        expect(Branch::where('is_default', true)->count())->toBe(1)
            ->and($norte->fresh()->is_default)->toBeTrue()
            ->and($this->principal->fresh()->is_default)->toBeFalse();
    });

    it('no deja apagar la principal', function () {
        Livewire::test(Branches::class)->call('toggle', $this->principal->id);

        expect($this->principal->fresh()->status)->toBe('active');
    });

    it('apaga y enciende una que no es la principal', function () {
        $norte = Branch::create(['code' => 'NOR', 'name' => 'Sucursal norte']);

        Livewire::test(Branches::class)->call('toggle', $norte->id);
        expect($norte->fresh()->status)->toBe('inactive');

        Livewire::test(Branches::class)->call('toggle', $norte->id);
        expect($norte->fresh()->status)->toBe('active');
    });

    it('invita a abrir la segunda para poder traspasar', function () {
        Livewire::test(Branches::class)->assertSee('traspasos de mercancia');
    });
});
