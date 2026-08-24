<div>
    <x-page-header title="Usuarios"
                   :subtitle="$users->where('status', 'active')->count() . ' activo(s) de ' . $users->count()">
        <x-slot:actions>
            @can('users.manage')
                <x-button size="sm" wire:click="create">+ Usuario</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.settings-tabs')

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @foreach ($users as $user)
            <x-card wire:key="user-{{ $user->id }}"
                @class(['opacity-60' => $user->status !== 'active'])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-medium text-slate-900 truncate">{{ $user->name }}</p>
                            @if ($user->id === auth()->id())
                                <span class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-xs">tu</span>
                            @endif
                            @if ($user->status !== 'active')
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">apagado</span>
                            @endif
                        </div>

                        <p class="text-sm text-slate-500 truncate">{{ $user->email }}</p>

                        <div class="flex flex-wrap items-center gap-1.5 mt-2">
                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-xs">
                                {{ $user->role?->name ?? 'Sin rol' }}
                            </span>
                            @if ($user->branch)
                                <span class="text-xs text-slate-500">{{ $user->branch->name }}</span>
                            @endif
                            @if ($user->permissions_override)
                                <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-700 text-xs">
                                    {{ count($user->permissions_override) }} excepcion(es)
                                </span>
                            @endif
                        </div>

                        <p class="text-xs text-slate-400 mt-1.5">
                            @if ($user->last_login_at)
                                Ultima entrada {{ $user->last_login_at->format('d/m/Y H:i') }}
                            @else
                                Todavia no ha entrado
                            @endif
                        </p>
                    </div>

                    @can('users.manage')
                        <div class="flex flex-col items-end gap-1.5 shrink-0">
                            <x-button variant="ghost" size="sm" wire:click="edit('{{ $user->id }}')">
                                Editar
                            </x-button>
                            <x-button variant="ghost" size="sm" wire:click="openPermissions('{{ $user->id }}')">
                                Permisos
                            </x-button>
                            @unless ($user->id === auth()->id())
                                <button type="button" wire:click="toggle('{{ $user->id }}')"
                                        class="text-xs text-slate-400 hover:text-slate-700 px-3">
                                    {{ $user->status === 'active' ? 'Apagar' : 'Activar' }}
                                </button>
                            @endunless
                        </div>
                    @endcan
                </div>
            </x-card>
        @endforeach
    </div>

    {{-- ==================== Alta y edicion ==================== --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar usuario' : 'Nuevo usuario'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Rosa Diaz"
                         :error="$errors->first('name')" />

                <x-input label="Correo" type="email" wire:model="email" placeholder="rosa@negocio.com"
                         hint="Con este correo entra al sistema"
                         :error="$errors->first('email')" />

                <x-input label="Telefono (opcional)" wire:model="phone" placeholder="9900-0000"
                         :error="$errors->first('phone')" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Rol</label>
                        <select wire:model="roleId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                       focus:ring-2 focus:ring-indigo-500">
                            @foreach ($this->roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleId')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Sucursal</label>
                        <select wire:model="branchId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                       focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin sucursal fija</option>
                            @foreach ($this->branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-input label="{{ $editingId ? 'Contrasena nueva' : 'Contrasena' }}"
                             type="password" wire:model="password"
                             hint="{{ $editingId ? 'En blanco, no cambia' : 'Al menos 8 caracteres' }}"
                             :error="$errors->first('password')" />

                    <x-input label="Repetir contrasena" type="password" wire:model="passwordConfirmation" />
                </div>

                <x-input label="PIN de caja (opcional)" type="text" inputmode="numeric"
                         wire:model="pin" placeholder="1234"
                         hint="De 4 a 6 numeros, para autorizar en la caja"
                         :error="$errors->first('pin')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- ==================== Excepciones de permisos ==================== --}}
    @if ($showPermissions)
        <x-modal title="Permisos de esta persona" wire="showPermissions">
            <form wire:submit="savePermissions" class="space-y-4">
                <p class="text-sm text-slate-600">
                    Lo normal es dejar todo en <strong>Del rol</strong>. Cambia solo lo que esta
                    persona necesita distinto: asi no hace falta inventarle un rol nuevo.
                </p>

                <div class="space-y-4">
                    @foreach (\App\Support\Permissions::catalog() as $key => $group)
                        <div>
                            <p class="text-sm font-medium text-slate-700 mb-2">
                                <span class="text-slate-400 mr-1">{{ $group['icon'] }}</span>
                                {{ $group['label'] }}
                            </p>

                            <div class="space-y-1.5">
                                @foreach ($group['abilities'] as $ability => $label)
                                    @php
                                        $fromRole = ($this->rolePermissions[$ability] ?? null) === true
                                            || ($this->rolePermissions['*'] ?? null) === true;
                                    @endphp
                                    <div wire:key="perm-{{ $ability }}"
                                         class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                        <div class="min-w-0">
                                            <p class="text-sm text-slate-700">{{ $label }}</p>
                                            <p class="text-xs text-slate-400">
                                                el rol {{ $fromRole ? 'lo permite' : 'no lo permite' }}
                                            </p>
                                        </div>

                                        <select wire:model="overrides.{{ \App\Support\Permissions::key($ability) }}"
                                                class="shrink-0 rounded-lg border border-slate-300 px-2 py-1.5 text-xs
                                                       focus:ring-2 focus:ring-indigo-500">
                                            <option value="inherit">Del rol</option>
                                            <option value="yes">Si</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showPermissions', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar permisos</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
