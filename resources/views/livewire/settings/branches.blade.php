<div>
    <x-page-header title="Sucursales"
                   :subtitle="$branches->count() . ' sucursal(es)'">
        <x-slot:actions>
            @can('settings.edit')
                <x-button size="sm" wire:click="create">+ Sucursal</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @foreach ($branches as $branch)
            <x-card wire:key="branch-{{ $branch->id }}"
                @class(['opacity-60' => $branch->status !== 'active'])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs font-medium">
                                {{ $branch->code }}
                            </span>
                            <p class="font-medium text-slate-900 truncate">{{ $branch->name }}</p>
                            @if ($branch->is_default)
                                <span class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-xs">
                                    principal
                                </span>
                            @endif
                            @if ($branch->status !== 'active')
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">
                                    apagada
                                </span>
                            @endif
                        </div>

                        @if ($branch->address)
                            <p class="text-sm text-slate-500 mt-1">{{ $branch->address }}</p>
                        @endif
                        @if ($branch->phone)
                            <p class="text-sm text-slate-500">Tel. {{ $branch->phone }}</p>
                        @endif

                        <p class="text-xs text-slate-500 mt-2">
                            {{ ($terminals[$branch->id] ?? collect())->count() }} caja(s)
                            @php $units = (float) ($stock[$branch->id] ?? 0); @endphp
                            @if ($units > 0)
                                · {{ rtrim(rtrim(number_format($units, 3), '0'), '.') }} unidades en existencia
                            @endif
                        </p>
                    </div>

                    @can('settings.edit')
                        <div class="flex flex-col items-end gap-1.5 shrink-0">
                            <x-button variant="ghost" size="sm" wire:click="edit('{{ $branch->id }}')">
                                Editar
                            </x-button>
                            @unless ($branch->is_default)
                                <x-button variant="ghost" size="sm" wire:click="makeDefault('{{ $branch->id }}')">
                                    Hacer principal
                                </x-button>
                            @endunless
                            <button type="button" wire:click="toggle('{{ $branch->id }}')"
                                    class="text-xs text-slate-400 hover:text-slate-700 px-3">
                                {{ $branch->status === 'active' ? 'Apagar' : 'Encender' }}
                            </button>
                        </div>
                    @endcan
                </div>
            </x-card>
        @endforeach
    </div>

    @if ($branches->count() === 1)
        <p class="mt-4 text-sm text-slate-500">
            Con una segunda sucursal se habilitan los traspasos de mercancia entre tiendas.
        </p>
    @endif

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar sucursal' : 'Nueva sucursal'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <x-input label="Codigo" wire:model="code" placeholder="NORTE"
                             hint="Corto y sin espacios"
                             :error="$errors->first('code')" />

                    <div class="sm:col-span-2">
                        <x-input label="Nombre" wire:model="name" placeholder="Sucursal norte"
                                 :error="$errors->first('name')" />
                    </div>
                </div>

                <x-input label="Direccion (opcional)" wire:model="address"
                         placeholder="Av. Central 120"
                         :error="$errors->first('address')" />

                <x-input label="Telefono (opcional)" wire:model="phone"
                         placeholder="2200-0000"
                         :error="$errors->first('phone')" />

                @unless ($editingId)
                    <p class="text-sm text-slate-500">
                        Se le crea tambien su caja y sus series de folios, para que se pueda
                        vender ahi desde el primer dia.
                    </p>
                @endunless

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
