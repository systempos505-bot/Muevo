<?php

namespace App\Livewire\Settings;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Usuarios del negocio.
 *
 * Cada persona entra con su propia cuenta: es lo unico que hace que el
 * reporte por cajero signifique algo y que un descuento tenga nombre.
 *
 * La pantalla protege contra quedarse afuera: nadie puede apagarse a si
 * mismo ni dejar al negocio sin un administrador activo.
 */
#[Layout('layouts.app')]
class Users extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $roleId = '';

    public string $branchId = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $pin = '';

    // --- Excepciones de permisos ---
    public bool $showPermissions = false;

    public ?string $permissionsUserId = null;

    /**
     * [clave de pantalla => 'inherit'|'yes'|'no']
     *
     * La clave va sin puntos porque Livewire los lee como niveles de
     * arreglo; Permissions::ability() la traduce de vuelta al guardar.
     */
    public array $overrides = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('users.view'), 403);
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('name')->get();
    }

    #[Computed]
    public function branches()
    {
        return Branch::active()->orderBy('name')->get();
    }

    /**
     * Administradores activos que quedan.
     *
     * Se cuenta antes de apagar o cambiar de rol a alguien: si el ultimo
     * se queda afuera, no hay forma de volver a entrar a administrar.
     */
    protected function activeAdmins(?string $excludingId = null): int
    {
        return User::where('status', 'active')
            ->when($excludingId, fn ($q) => $q->whereKeyNot($excludingId))
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('users.manage'))
            ->count();
    }

    // =========================================================
    // Alta y edicion
    // =========================================================

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => [
                'required', 'email', 'max:120',
                Rule::unique('users', 'email')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'roleId' => ['required', Rule::exists('roles', 'id')->where('tenant_id', auth()->user()->tenant_id)],
            'branchId' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', auth()->user()->tenant_id)],
            // Al editar, en blanco significa "dejala como estaba".
            'password' => [
                Rule::requiredIf(fn () => $this->editingId === null),
                'nullable', 'string', 'min:8', 'same:passwordConfirmation',
            ],
            'pin' => ['nullable', 'digits_between:4,6'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la persona.',
            'email.unique' => 'Ya hay alguien con ese correo en el negocio.',
            'roleId.required' => 'Elige que puede hacer esta persona.',
            'password.required' => 'Ponle una contrasena para que pueda entrar.',
            'password.min' => 'La contrasena necesita al menos 8 caracteres.',
            'password.same' => 'Las dos contrasenas no coinciden.',
            'pin.digits_between' => 'El PIN de caja va de 4 a 6 numeros.',
        ];
    }

    public function create(): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $this->reset(['editingId', 'name', 'email', 'phone', 'password', 'passwordConfirmation', 'pin']);
        $this->roleId = (string) ($this->roles->firstWhere('code', 'cashier')?->id ?? $this->roles->first()?->id);
        $this->branchId = (string) (auth()->user()->branch_id ?? $this->branches->first()?->id);

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $user = User::findOrFail($id);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->roleId = (string) $user->role_id;
        $this->branchId = (string) $user->branch_id;
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->pin = '';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $data = $this->validate();

        // Cambiarse a si mismo a un rol sin administracion es cerrarse la
        // puerta desde adentro.
        if ($this->editingId === auth()->id() && ! $this->roleGrantsManagement($data['roleId'])) {
            $this->addError('roleId', 'No puedes quitarte a ti mismo la administracion.');

            return;
        }

        if ($this->editingId !== null
            && ! $this->roleGrantsManagement($data['roleId'])
            && $this->userIsAdmin($this->editingId)
            && $this->activeAdmins($this->editingId) === 0) {
            $this->addError('roleId', 'Es el unico administrador: el negocio quedaria sin quien lo administre.');

            return;
        }

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?: null,
            'role_id' => $data['roleId'],
            'branch_id' => $data['branchId'] ?: null,
        ];

        // En blanco significa "dejala como estaba": obligar a reescribir
        // la contrasena para corregir un telefono no protege de nada.
        if ($data['password']) {
            $attributes['password'] = $data['password'];
        }

        if ($data['pin']) {
            $attributes['pin'] = $data['pin'];
        }

        if ($this->editingId !== null) {
            User::findOrFail($this->editingId)->update($attributes);
        } else {
            User::create([...$attributes, 'status' => 'active']);
        }

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'email', 'phone', 'password', 'passwordConfirmation', 'pin']);
        $this->notify('Usuario guardado');
    }

    /** Si un rol puede administrar usuarios. */
    protected function roleGrantsManagement(string $roleId): bool
    {
        $permissions = Role::whereKey($roleId)->value('permissions') ?? [];

        return ($permissions['users.manage'] ?? null) === true
            || ($permissions[Permissions::WILDCARD] ?? null) === true;
    }

    protected function userIsAdmin(string $userId): bool
    {
        return (bool) User::find($userId)?->hasPermission('users.manage');
    }

    // =========================================================
    // Estado
    // =========================================================

    public function toggle(string $id): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            $this->notify('No puedes apagar tu propia cuenta.', 'error');

            return;
        }

        if ($user->status === 'active'
            && $user->hasPermission('users.manage')
            && $this->activeAdmins($user->id) === 0) {
            $this->notify('Es el unico administrador activo: el negocio quedaria sin quien lo administre.', 'error');

            return;
        }

        $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);

        $this->notify($user->status === 'active' ? 'Usuario activado' : 'Usuario apagado');
    }

    // =========================================================
    // Excepciones de permisos
    // =========================================================

    public function openPermissions(string $id): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $user = User::with('role')->findOrFail($id);
        $this->permissionsUserId = $user->id;

        $override = $user->permissions_override ?? [];

        // Tres estados y no una casilla: un permiso puede venir del rol,
        // estar dado a esta persona en concreto, o estarle quitado aunque
        // el rol lo tenga. Una casilla solo sabe decir dos cosas.
        $this->overrides = collect(Permissions::all())
            ->mapWithKeys(fn (string $ability) => [
                Permissions::key($ability) => array_key_exists($ability, $override)
                    ? ($override[$ability] ? 'yes' : 'no')
                    : 'inherit',
            ])
            ->all();

        $this->showPermissions = true;
    }

    public function savePermissions(): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $user = User::findOrFail($this->permissionsUserId);

        $override = collect($this->overrides)
            ->filter(fn ($state) => is_string($state) && $state !== 'inherit')
            ->mapWithKeys(fn (string $state, string $key) => [
                Permissions::ability($key) => $state === 'yes',
            ])
            ->all();

        if ($user->id === auth()->id() && ($override['users.manage'] ?? null) === false) {
            $this->notify('No puedes quitarte a ti mismo la administracion.', 'error');

            return;
        }

        $user->update(['permissions_override' => $override ?: null]);

        $this->showPermissions = false;
        $this->notify('Permisos actualizados');
    }

    /** Lo que el rol da por si solo, para dibujar el estado heredado. */
    #[Computed]
    public function rolePermissions(): array
    {
        if ($this->permissionsUserId === null) {
            return [];
        }

        return User::with('role')->find($this->permissionsUserId)?->role?->permissions ?? [];
    }

    public function render()
    {
        return view('livewire.settings.users', [
            'users' => User::with(['role', 'branch'])
                ->orderBy('status')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
