<div>
    <x-page-header title="Roles" subtitle="Que puede hacer cada clase de persona">
        <x-slot:actions>
            @can('settings.edit')
                <x-button size="sm" wire:click="create">+ Rol</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.settings-tabs')

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @foreach ($roles as $role)
            @php
                $isAdmin = $this->isAdminRole($role);
                $granted = \App\Support\Permissions::fromMap($role->permissions);
                $users = $this->userCounts[$role->id] ?? 0;
            @endphp

            <x-card wire:key="role-{{ $role->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-medium text-slate-900 truncate">{{ $role->name }}</p>
                            @if ($isAdmin)
                                <span class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-xs">
                                    todo permitido
                                </span>
                            @endif
                        </div>

                        <p class="text-xs text-slate-500 mt-1">
                            {{ $users }} persona(s)
                            @unless ($isAdmin)
                                · {{ count($granted) }} de {{ count(\App\Support\Permissions::all()) }} permisos
                            @endunless
                        </p>

                        @unless ($isAdmin)
                            <div class="flex flex-wrap gap-1 mt-2">
                                @foreach (array_slice($granted, 0, 6) as $ability)
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">
                                        {{ \App\Support\Permissions::label($ability) }}
                                    </span>
                                @endforeach
                                @if (count($granted) > 6)
                                    <span class="px-2 py-0.5 text-xs text-slate-400">
                                        y {{ count($granted) - 6 }} mas
                                    </span>
                                @endif
                            </div>
                        @endunless
                    </div>

                    @can('settings.edit')
                        @unless ($isAdmin)
                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <x-button variant="ghost" size="sm" wire:click="edit('{{ $role->id }}')">
                                    Editar
                                </x-button>
                                @if ($users === 0)
                                    <button type="button" wire:click="delete('{{ $role->id }}')"
                                            class="text-xs text-slate-400 hover:text-rose-600 px-3">
                                        Eliminar
                                    </button>
                                @endif
                            </div>
                        @endunless
                    @endcan
                </div>
            </x-card>
        @endforeach
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar rol' : 'Nuevo rol'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre del rol" wire:model="name" placeholder="Encargado de turno"
                         :error="$errors->first('name')" />

                @error('abilities')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="space-y-4">
                    @foreach ($this->catalog as $key => $group)
                        @php
                            $keys = array_keys($group['abilities']);
                            $allOn = count(array_intersect($keys, $abilities)) === count($keys);
                        @endphp

                        <div class="rounded-lg border border-slate-200 overflow-hidden">
                            <div class="flex items-center justify-between px-3 py-2 bg-slate-50">
                                <p class="text-sm font-medium text-slate-700">
                                    <span class="text-slate-400 mr-1">{{ $group['icon'] }}</span>
                                    {{ $group['label'] }}
                                </p>
                                {{-- Marcar de a una las treinta casillas es trabajo
                                     que nadie quiere hacer dos veces. --}}
                                <button type="button" wire:click="toggleGroup('{{ $key }}')"
                                        class="text-xs text-indigo-600 hover:underline">
                                    {{ $allOn ? 'Quitar todo' : 'Marcar todo' }}
                                </button>
                            </div>

                            <div class="p-3 space-y-2">
                                @foreach ($group['abilities'] as $ability => $label)
                                    <label wire:key="ab-{{ $ability }}"
                                           class="flex items-center gap-2.5 text-sm text-slate-700 cursor-pointer">
                                        <input type="checkbox" value="{{ $ability }}"
                                               wire:model.live="abilities"
                                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar rol</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
