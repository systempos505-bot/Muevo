<div>
    <x-page-header title="Proveedores" :subtitle="$suppliers->total() . ' registrado(s)'">
        <x-slot:actions>
            @can('purchases.create')
                <x-button size="sm" wire:click="create">+ Proveedor</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($this->totalPayable > 0)
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 flex items-center justify-between">
            <span class="text-sm text-amber-900">Total por pagar a proveedores</span>
            <span class="text-xl font-bold tabular-nums text-amber-900">
                {{ $currency?->symbol }}{{ number_format($this->totalPayable, 2) }}
            </span>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por nombre, identificacion o telefono"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="filter"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="all">Todos</option>
            <option value="active">Activos</option>
            <option value="debt">Con saldo</option>
        </select>
    </div>

    @if ($suppliers->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">☖</p>
            <p class="mt-3 font-medium text-slate-900">
                {{ $search ? 'Sin resultados' : 'Aun no tienes proveedores' }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                {{ $search
                    ? 'Prueba con otro termino.'
                    : 'Registralos para llevar tus compras y cuentas por pagar.' }}
            </p>
            @can('purchases.create')
                @unless ($search)
                    <div class="mt-5"><x-button wire:click="create">Crear el primero</x-button></div>
                @endunless
            @endcan
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Proveedor</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Contacto</th>
                            <th class="px-5 py-3 font-medium text-right hidden lg:table-cell">Productos</th>
                            <th class="px-5 py-3 font-medium text-right hidden lg:table-cell">Dias credito</th>
                            <th class="px-5 py-3 font-medium text-right">Saldo</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($suppliers as $supplier)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <p @class([
                                        'font-medium',
                                        'text-slate-900' => $supplier->status === 'active',
                                        'text-slate-400 line-through' => $supplier->status !== 'active',
                                    ])>{{ $supplier->name }}</p>
                                    @if ($supplier->tax_id)
                                        <p class="text-xs text-slate-500">{{ $supplier->tax_id }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $supplier->contact_name ?? '—' }}
                                    @if ($supplier->phone)
                                        <p class="text-xs text-slate-500">{{ $supplier->phone }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums hidden lg:table-cell">
                                    {{ $supplier->products_count }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums hidden lg:table-cell">
                                    {{ $supplier->credit_days > 0 ? $supplier->credit_days : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-amber-600' => $supplier->balance > 0,
                                        'text-slate-400' => $supplier->balance <= 0,
                                    ])>{{ $currency?->symbol }}{{ number_format($supplier->balance, 2) }}</span>
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @can('purchases.create')
                                        <button wire:click="edit('{{ $supplier->id }}')"
                                                class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                            Editar
                                        </button>
                                        <button wire:click="toggleStatus('{{ $supplier->id }}')"
                                                class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                            {{ $supplier->status === 'active' ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $suppliers->links() }}</div>
    @endif

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar proveedor' : 'Nuevo proveedor'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Distribuidora Central"
                         :error="$errors->first('name')" autofocus />

                <div class="grid grid-cols-2 gap-3">
                    <x-input label="Identificacion fiscal" wire:model="taxId" placeholder="Opcional"
                             :error="$errors->first('taxId')" />
                    <x-input label="Contacto" wire:model="contactName" placeholder="Opcional"
                             :error="$errors->first('contactName')" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-input label="Telefono" wire:model="phone" placeholder="Opcional" inputmode="tel"
                             :error="$errors->first('phone')" />
                    <x-input label="Correo" type="email" wire:model="email" placeholder="Opcional"
                             :error="$errors->first('email')" />
                </div>

                <x-input label="Direccion" wire:model="address" placeholder="Opcional"
                         :error="$errors->first('address')" />

                <x-input label="Dias de credito" type="number" min="0" max="365"
                         wire:model="creditDays" inputmode="numeric"
                         hint="0 si solo vende de contado"
                         :error="$errors->first('creditDays')" />

                <x-input label="Notas" wire:model="notes" placeholder="Opcional"
                         :error="$errors->first('notes')" />

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
