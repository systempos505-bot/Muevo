<?php

use App\Livewire\Settings\Users as UsersScreen;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->context = actingAsTenant();
    $this->owner = $this->context['user'];
    $this->adminRole = $this->context['setup']['admin_role'];
    $this->cashierRole = Role::where('code', 'cashier')->firstOrFail();
});

/** Crea un usuario con un rol dado. */
function makeUser(string $name, Role $role, string $email = 'otro@negocio.test'): User
{
    return User::create([
        'name' => $name,
        'email' => $email,
        'password' => 'clave-segura-1',
        'role_id' => $role->id,
        'branch_id' => test()->context['setup']['branch']->id,
    ]);
}

describe('acceso', function () {
    it('niega la pantalla a quien no puede ver usuarios', function () {
        $this->owner->update(['permissions_override' => ['*' => false, 'users.view' => false]]);

        $this->get(route('users'))->assertForbidden();
    });

    it('deja mirar pero no crear a quien solo puede ver', function () {
        $this->owner->update([
            'permissions_override' => ['*' => false, 'users.view' => true],
        ]);

        $this->get(route('users'))->assertOk()->assertDontSee('+ Usuario');

        Livewire::test(UsersScreen::class)->call('create')->assertForbidden();
    });
});

describe('alta', function () {
    it('crea un cajero que puede entrar', function () {
        Livewire::test(UsersScreen::class)
            ->call('create')
            ->set('name', 'Rosa Diaz')
            ->set('email', 'rosa@negocio.test')
            ->set('roleId', $this->cashierRole->id)
            ->set('password', 'clave-segura-1')
            ->set('passwordConfirmation', 'clave-segura-1')
            ->call('save')
            ->assertHasNoErrors();

        $rosa = User::where('email', 'rosa@negocio.test')->first();

        expect($rosa->status)->toBe('active')
            ->and($rosa->hasPermission('sales.create'))->toBeTrue()
            ->and($rosa->hasPermission('settings.edit'))->toBeFalse()
            ->and(Hash::check('clave-segura-1', $rosa->password))->toBeTrue();
    });

    it('no deja repetir el correo dentro del negocio', function () {
        Livewire::test(UsersScreen::class)
            ->call('create')
            ->set('name', 'Otra Rosa')
            ->set('email', $this->owner->email)
            ->set('roleId', $this->cashierRole->id)
            ->set('password', 'clave-segura-1')
            ->set('passwordConfirmation', 'clave-segura-1')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('exige que las dos contrasenas coincidan', function () {
        Livewire::test(UsersScreen::class)
            ->call('create')
            ->set('name', 'Rosa Diaz')
            ->set('email', 'rosa@negocio.test')
            ->set('roleId', $this->cashierRole->id)
            ->set('password', 'clave-segura-1')
            ->set('passwordConfirmation', 'otra-distinta')
            ->call('save')
            ->assertHasErrors(['password']);
    });

    it('pide contrasena al crear pero no al editar', function () {
        Livewire::test(UsersScreen::class)
            ->call('create')
            ->set('name', 'Rosa Diaz')
            ->set('email', 'rosa@negocio.test')
            ->set('roleId', $this->cashierRole->id)
            ->call('save')
            ->assertHasErrors(['password']);

        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        // Obligar a reescribir la contrasena para corregir un telefono no
        // protege de nada.
        Livewire::test(UsersScreen::class)
            ->call('edit', $rosa->id)
            ->set('phone', '9900-0000')
            ->call('save')
            ->assertHasNoErrors();

        expect(Hash::check('clave-segura-1', $rosa->fresh()->password))->toBeTrue();
    });

    it('guarda el PIN cifrado', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        Livewire::test(UsersScreen::class)
            ->call('edit', $rosa->id)
            ->set('pin', '4821')
            ->call('save')
            ->assertHasNoErrors();

        $pin = $rosa->fresh()->pin;

        expect($pin)->not->toBe('4821')
            ->and(Hash::check('4821', $pin))->toBeTrue();
    });

    it('rechaza un PIN que no son numeros', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        Livewire::test(UsersScreen::class)
            ->call('edit', $rosa->id)
            ->set('pin', 'abcd')
            ->call('save')
            ->assertHasErrors(['pin']);
    });
});

describe('no quedarse afuera', function () {
    it('no deja apagarse a si mismo', function () {
        Livewire::test(UsersScreen::class)->call('toggle', $this->owner->id);

        expect($this->owner->fresh()->status)->toBe('active');
    });

    it('no deja quitarse a si mismo la administracion', function () {
        Livewire::test(UsersScreen::class)
            ->call('edit', $this->owner->id)
            ->set('roleId', $this->cashierRole->id)
            ->call('save')
            ->assertHasErrors(['roleId']);

        expect($this->owner->fresh()->role_id)->toBe($this->adminRole->id);
    });

    it('no deja apagar al unico administrador', function () {
        $otroAdmin = makeUser('Segundo dueno', $this->adminRole, 'segundo@negocio.test');

        // Con dos administradores se puede apagar a uno.
        Livewire::test(UsersScreen::class)->call('toggle', $otroAdmin->id);
        expect($otroAdmin->fresh()->status)->toBe('inactive');

        // Y ahora el dueno es el unico que queda; no puede apagarse.
        Livewire::test(UsersScreen::class)->call('toggle', $this->owner->id);
        expect($this->owner->fresh()->status)->toBe('active');
    });

    it('no deja bajar de rol al unico administrador que queda', function () {
        $otroAdmin = makeUser('Segundo dueno', $this->adminRole, 'segundo@negocio.test');

        // Al otro si, porque el dueno sigue siendo administrador.
        Livewire::test(UsersScreen::class)
            ->call('edit', $otroAdmin->id)
            ->set('roleId', $this->cashierRole->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($otroAdmin->fresh()->role_id)->toBe($this->cashierRole->id);
    });

    it('deja apagar a alguien que no es administrador', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        Livewire::test(UsersScreen::class)->call('toggle', $rosa->id);
        expect($rosa->fresh()->status)->toBe('inactive');

        Livewire::test(UsersScreen::class)->call('toggle', $rosa->id);
        expect($rosa->fresh()->status)->toBe('active');
    });
});

describe('excepciones de permisos', function () {
    it('carga el estado heredado del rol', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        Livewire::test(UsersScreen::class)
            ->call('openPermissions', $rosa->id)
            ->assertSet('overrides.sales__create', 'inherit')
            ->assertSet('showPermissions', true);
    });

    it('le da un permiso que su rol no trae', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        expect($rosa->hasPermission('sales.discount'))->toBeFalse();

        Livewire::test(UsersScreen::class)
            ->call('openPermissions', $rosa->id)
            ->set('overrides.sales__discount', 'yes')
            ->call('savePermissions');

        expect($rosa->fresh()->hasPermission('sales.discount'))->toBeTrue();
    });

    it('le quita un permiso que su rol si trae', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        expect($rosa->hasPermission('sales.return'))->toBeTrue();

        Livewire::test(UsersScreen::class)
            ->call('openPermissions', $rosa->id)
            ->set('overrides.sales__return', 'no')
            ->call('savePermissions');

        expect($rosa->fresh()->hasPermission('sales.return'))->toBeFalse();
    });

    it('volver a heredar borra la excepcion', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');
        $rosa->update(['permissions_override' => ['sales.return' => false]]);

        Livewire::test(UsersScreen::class)
            ->call('openPermissions', $rosa->id)
            ->set('overrides.sales__return', 'inherit')
            ->call('savePermissions');

        expect($rosa->fresh()->permissions_override)->toBeNull()
            ->and($rosa->fresh()->hasPermission('sales.return'))->toBeTrue();
    });

    it('no deja quitarse a si mismo la administracion por excepcion', function () {
        Livewire::test(UsersScreen::class)
            ->call('openPermissions', $this->owner->id)
            ->set('overrides.users__manage', 'no')
            ->call('savePermissions');

        expect($this->owner->fresh()->hasPermission('users.manage'))->toBeTrue();
    });
});

describe('sesion de alguien apagado', function () {
    it('lo saca de la sesion que ya tenia abierta', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');

        $this->actingAs($rosa);
        $this->get(route('pos'))->assertOk();

        $rosa->update(['status' => 'inactive']);

        // Sin esto, quien ya tenia la sesion abierta seguiria vendiendo
        // hasta que se le ocurriera cerrarla.
        $this->get(route('pos'))->assertRedirect(route('login'));
        expect(auth()->check())->toBeFalse();
    });

    it('no lo deja volver a entrar', function () {
        $rosa = makeUser('Rosa Diaz', $this->cashierRole, 'rosa@negocio.test');
        $rosa->update(['status' => 'inactive']);

        auth()->logout();

        $this->post(route('login'), [
            'email' => 'rosa@negocio.test',
            'password' => 'clave-segura-1',
        ]);

        expect(auth()->check())->toBeFalse();
    });
});
