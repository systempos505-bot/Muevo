<?php

namespace App\Livewire\Settings;

use App\Livewire\Page;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Roles y sus permisos.
 *
 * Un rol es "que puede hacer esta clase de persona". Cambiarlo cambia a
 * todos los que lo tienen a la vez, que es justo lo que se quiere cuando
 * el negocio decide que los cajeros ya no dan descuentos.
 *
 * El rol de administrador no se toca: es la garantia de que siempre queda
 * alguien que pueda entrar a arreglar lo que se haya roto.
 */
#[Layout('layouts.app')]
class Roles extends Page
{
    /** Codigo del rol que abre todo. No se edita ni se borra. */
    public const ADMIN_CODE = 'admin';

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    /** @var array<int, string> */
    public array $abilities = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);
    }

    #[Computed]
    public function catalog(): array
    {
        return Permissions::catalog();
    }

    /** Cuantas personas tiene cada rol, para no borrar uno en uso. */
    #[Computed]
    public function userCounts()
    {
        return User::selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in(Permissions::all())],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre al rol.',
            'name.unique' => 'Ya tienes un rol con ese nombre.',
        ];
    }

    public function create(): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $this->reset(['editingId', 'name', 'abilities']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $role = Role::findOrFail($id);

        if ($this->isAdminRole($role)) {
            $this->notify('El rol de administrador no se edita: es lo que garantiza que siempre haya quien administre.', 'error');

            return;
        }

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->abilities = Permissions::fromMap($role->permissions);

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $data = $this->validate();

        if ($this->editingId !== null && $this->isAdminRole(Role::findOrFail($this->editingId))) {
            $this->notify('El rol de administrador no se edita.', 'error');

            return;
        }

        if ($data['abilities'] === []) {
            $this->addError('abilities', 'Un rol sin ningun permiso no le sirve a nadie.');

            return;
        }

        Role::updateOrCreate(
            ['id' => $this->editingId],
            [
                'code' => $this->editingId
                    ? Role::whereKey($this->editingId)->value('code')
                    : $this->uniqueCode($data['name']),
                'name' => $data['name'],
                // Se filtra contra el catalogo: una clave inventada
                // quedaria en la base para siempre sin significar nada.
                'permissions' => Permissions::toMap($data['abilities']),
                'is_system' => false,
            ],
        );

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'abilities']);
        $this->notify('Rol guardado');
    }

    /**
     * Marca o desmarca un modulo entero.
     *
     * Armar un rol marcando de a una las treinta casillas es trabajo que
     * nadie quiere hacer dos veces.
     */
    public function toggleGroup(string $group): void
    {
        $abilities = array_keys($this->catalog[$group]['abilities'] ?? []);

        $this->abilities = count(array_intersect($abilities, $this->abilities)) === count($abilities)
            ? array_values(array_diff($this->abilities, $abilities))
            : array_values(array_unique([...$this->abilities, ...$abilities]));
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $role = Role::findOrFail($id);

        if ($this->isAdminRole($role)) {
            $this->notify('El rol de administrador no se borra.', 'error');

            return;
        }

        // Borrar un rol en uso dejaria usuarios apuntando a nada, y sin
        // permisos no podrian ni entrar.
        if (($this->userCounts[$role->id] ?? 0) > 0) {
            $this->notify('Ese rol lo tienen usuarios: cambialos de rol primero.', 'error');

            return;
        }

        $role->delete();
        unset($this->userCounts);

        $this->notify('Rol eliminado');
    }

    public function isAdminRole(Role $role): bool
    {
        return $role->code === self::ADMIN_CODE
            || ($role->permissions[Permissions::WILDCARD] ?? null) === true;
    }

    /** Un codigo estable a partir del nombre, sin chocar con los que hay. */
    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'rol';
        $code = $base;
        $suffix = 2;

        while (Role::where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }

    public function render()
    {
        return view('livewire.settings.roles', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }
}
