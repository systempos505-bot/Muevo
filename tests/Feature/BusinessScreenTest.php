<?php

use App\Livewire\Settings\Business;
use App\Models\Currency;
use App\Models\Tax;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
});

describe('acceso', function () {
    it('niega la pantalla a quien no puede ver la configuracion', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'settings.view' => false],
        ]);

        $this->get(route('business'))->assertForbidden();
    });

    it('deja mirar pero no guardar a quien solo puede ver', function () {
        $this->context['user']->update([
            'permissions_override' => ['*' => false, 'settings.view' => true],
        ]);

        $this->get(route('business'))->assertOk()->assertDontSee('Guardar cambios');

        Livewire::test(Business::class)
            ->set('name', 'Otro nombre')
            ->call('save')
            ->assertForbidden();
    });
});

describe('carga inicial', function () {
    it('trae los datos actuales de la empresa, la moneda y el impuesto', function () {
        Livewire::test(Business::class)
            ->assertSet('name', 'Negocio de prueba')
            ->assertSet('currencySymbol', '$')
            ->assertSet('taxRate', 15.0);
    });
});

describe('guardado', function () {
    it('actualiza el nombre, la direccion y el telefono del ticket', function () {
        Livewire::test(Business::class)
            ->set('name', 'Boutique Las Rosas')
            ->set('tradeName', 'Las Rosas')
            ->set('address', 'Av. Central 120')
            ->set('phone', '2200-0000')
            ->call('save')
            ->assertHasNoErrors();

        $tenant = Tenant::whereKey($this->context['tenant']->id)->first();

        expect($tenant->name)->toBe('Boutique Las Rosas')
            ->and($tenant->trade_name)->toBe('Las Rosas')
            ->and($tenant->address)->toBe('Av. Central 120')
            ->and($tenant->phone)->toBe('2200-0000');
    });

    it('exige un nombre', function () {
        Livewire::test(Business::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('no deja repetir el correo de otra empresa', function () {
        actingAsTenant('general', 'otra@negocio.test');

        Livewire::test(Business::class)
            ->set('email', 'dueno@negocio.test')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('deja el correo tal cual si no se toca', function () {
        Livewire::test(Business::class)
            ->set('name', 'Con el mismo correo')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('actualiza el simbolo y los decimales de la moneda principal', function () {
        Livewire::test(Business::class)
            ->set('currencySymbol', 'L')
            ->set('currencyDecimals', 0)
            ->call('save')
            ->assertHasNoErrors();

        $currency = Currency::where('is_primary', true)->first();

        expect($currency->symbol)->toBe('L')
            ->and($currency->decimals)->toBe(0);
    });

    it('actualiza el impuesto por defecto', function () {
        Livewire::test(Business::class)
            ->set('taxRate', 18)
            ->call('save')
            ->assertHasNoErrors();

        expect((float) Tax::where('is_default', true)->value('rate'))->toBe(18.0);
    });

    it('exige un simbolo de moneda', function () {
        Livewire::test(Business::class)
            ->set('currencySymbol', '')
            ->call('save')
            ->assertHasErrors(['currencySymbol']);
    });

    it('no deja un impuesto negativo ni mayor a cien', function () {
        Livewire::test(Business::class)
            ->set('taxRate', -5)
            ->call('save')
            ->assertHasErrors(['taxRate']);

        Livewire::test(Business::class)
            ->set('taxRate', 150)
            ->call('save')
            ->assertHasErrors(['taxRate']);
    });

    it('no expone el modo de impuesto como algo que se pueda cambiar', function () {
        // Voltearlo reinterpretaria de golpe cada precio ya guardado, como
        // si el negocio hubiera subido sus precios de la noche a la manana.
        Livewire::test(Business::class)
            ->set('pricesIncludeTax', false)
            ->call('save');

        expect(Tenant::whereKey($this->context['tenant']->id)->value('prices_include_tax'))
            ->toBeTrue();
    });
});
