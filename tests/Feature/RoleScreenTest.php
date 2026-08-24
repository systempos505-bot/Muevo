<?php

use App\Livewire\Settings\Roles as RolesScreen;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->owner = $this->context['user'];
    $this->adminRole = $this->context['setup']['admin_role'];
    $this->cashierRole = Role::where('code', 'cashier')->firstOrFail();
});

describe('acceso', function () {
    it('niega la pantalla a quien no ve la configuracion', function () {
        $this->owner->update(['permissions_override' => ['*' => false, 'settings.view' => false]]);

        $this->get(route('roles'))->assertForbidden();
    });

    it('deja mirar pero no crear a quien solo puede ver', function () {
        $this->owner->update([
            'permissions_override' => ['*' => false, 'settings.view' => true],
        ]);

        $this->get(route('roles'))->assertOk()->assertDontSee('+ Rol');

        Livewire::test(RolesScreen::class)->call('create')->assertForbidden();
    });
});

describe('alta', function () {
    it('crea un rol con los permisos marcados', function () {
        Livewire::test(RolesScreen::class)
            ->call('create')
            ->set('name', 'Encargado de turno')
            ->set('abilities', ['sales.create', 'sales.void', 'cash.close'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('name', 'Encargado de turno')->first();

        expect($role->permissions)->toBe([
            'sales.create' => true,
            'sales.void' => true,
            'cash.close' => true,
        ])->and($role->is_system)->toBeFalse();
    });

    it('rechaza un rol sin ningun permiso', function () {
        Livewire::test(RolesScreen::class)
            ->call('create')
            ->set('name', 'Rol vacio')
            ->set('abilities', [])
            ->call('save')
            ->assertHasErrors(['abilities']);

        expect(Role::where('name', 'Rol vacio')->exists())->toBeFalse();
    });

    it('rechaza un permiso que no existe', function () {
        // Las casillas salen del catalogo, asi que una clave desconocida
        // significa que alguien toco lo que se manda. No se guarda.
        Livewire::test(RolesScreen::class)
            ->call('create')
            ->set('name', 'Con basura')
            ->set('abilities', ['sales.create', 'inventado.permiso'])
            ->call('save')
            ->assertHasErrors(['abilities.1']);

        expect(Role::where('name', 'Con basura')->exists())->toBeFalse();
    });

    it('el mapa de permisos tampoco deja pasar una clave inventada', function () {
        // Segunda linea de defensa: si algun dia se guarda desde otro
        // lado, la clave inventada no llega a la base.
        expect(Permissions::toMap(['sales.create', 'inventado.permiso']))
            ->toBe(['sales.create' => true]);
    });

    it('no deja repetir el nombre', function () {
        Livewire::test(RolesScreen::class)
            ->call('create')
            ->set('name', $this->cashierRole->name)
            ->set('abilities', ['sales.create'])
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('le da un codigo propio a cada rol', function () {
        $screen = Livewire::test(RolesScreen::class);

        foreach (['Encargado', 'Encargado de bodega'] as $name) {
            $screen->call('create')->set('name', $name)
                ->set('abilities', ['sales.create'])
                ->call('save');
        }

        $codes = Role::pluck('code');

        expect($codes->unique())->toHaveCount($codes->count());
    });
});

describe('edicion', function () {
    it('carga los permisos del rol en el formulario', function () {
        Livewire::test(RolesScreen::class)
            ->call('edit', $this->cashierRole->id)
            ->assertSet('name', 'Cajero')
            ->assertSet('showForm', true)
            ->assertSet('abilities', Permissions::fromMap($this->cashierRole->permissions));
    });

    it('cambiar el rol cambia a todos los que lo tienen', function () {
        $rosa = User::create([
            'name' => 'Rosa',
            'email' => 'rosa@negocio.test',
            'password' => 'clave-segura-1',
            'role_id' => $this->cashierRole->id,
        ]);

        expect($rosa->hasPermission('sales.discount'))->toBeFalse();

        Livewire::test(RolesScreen::class)
            ->call('edit', $this->cashierRole->id)
            ->set('abilities', ['sales.create', 'sales.discount'])
            ->call('save');

        // Es lo que se quiere cuando el negocio decide que los cajeros ya
        // no dan descuentos, o que si.
        expect($rosa->fresh()->hasPermission('sales.discount'))->toBeTrue();
    });

    it('marcar todo el grupo enciende sus permisos de una vez', function () {
        $inventario = array_keys(Permissions::catalog()['inventory']['abilities']);

        $screen = Livewire::test(RolesScreen::class)
            ->call('create')
            ->call('toggleGroup', 'inventory');

        expect($screen->get('abilities'))->toEqualCanonicalizing($inventario);

        $screen->call('toggleGroup', 'inventory');

        expect($screen->get('abilities'))->toBe([]);
    });
});

describe('el administrador no se toca', function () {
    it('no deja editarlo', function () {
        Livewire::test(RolesScreen::class)
            ->call('edit', $this->adminRole->id)
            ->assertSet('showForm', false);
    });

    it('no deja guardarle permisos aunque se intente por fuera', function () {
        Livewire::test(RolesScreen::class)
            ->set('editingId', $this->adminRole->id)
            ->set('name', 'Administrador recortado')
            ->set('abilities', ['sales.create'])
            ->call('save');

        // Es la garantia de que siempre queda alguien que pueda entrar a
        // arreglar lo que se haya roto.
        expect($this->adminRole->fresh()->permissions)->toBe(['*' => true]);
    });

    it('no deja borrarlo', function () {
        Livewire::test(RolesScreen::class)->call('delete', $this->adminRole->id);

        expect(Role::whereKey($this->adminRole->id)->exists())->toBeTrue();
    });
});

describe('borrado', function () {
    it('borra un rol que nadie tiene', function () {
        $role = Role::create([
            'code' => 'temporal',
            'name' => 'Temporal',
            'permissions' => ['sales.create' => true],
        ]);

        Livewire::test(RolesScreen::class)->call('delete', $role->id);

        expect(Role::whereKey($role->id)->exists())->toBeFalse();
    });

    it('no borra un rol que tienen usuarios', function () {
        User::create([
            'name' => 'Rosa',
            'email' => 'rosa@negocio.test',
            'password' => 'clave-segura-1',
            'role_id' => $this->cashierRole->id,
        ]);

        // Sin rol no tendrian permisos y no podrian ni entrar.
        Livewire::test(RolesScreen::class)->call('delete', $this->cashierRole->id);

        expect(Role::whereKey($this->cashierRole->id)->exists())->toBeTrue();
    });
});
