<div>
    <x-page-header title="Clientes" :subtitle="$customers->total() . ' registrado(s)'">
        <x-slot:actions>
            @can('customers.create')
                <x-button size="sm" wire:click="create">+ Cliente</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($this->totalReceivable > 0)
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 flex items-center justify-between">
            <span class="text-sm text-amber-900">Total por cobrar a clientes</span>
            <span class="text-xl font-bold tabular-nums text-amber-900">
                {{ $currency?->symbol }}{{ number_format($this->totalReceivable, 2) }}
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
            <option value="credit">Con credito</option>
            <option value="debt">Con saldo</option>
        </select>
    </div>

    @if ($customers->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">☺</p>
            <p class="mt-3 font-medium text-slate-900">
                {{ $search ? 'Sin resultados' : 'Aun no tienes clientes' }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                {{ $search
                    ? 'Prueba con otro termino.'
                    : 'Registralos para llevar su historial, su lista de precios y su credito.' }}
            </p>
            @can('customers.create')
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
                            <th class="px-5 py-3 font-medium">Cliente</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Tipo</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Telefono</th>
                            <th class="px-5 py-3 font-medium text-right hidden lg:table-cell">Limite</th>
                            <th class="px-5 py-3 font-medium text-right">Saldo</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($customers as $customer)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('customers.show', ['customerId' => $customer->id]) }}"
                                       wire:navigate
                                       @class([
                                           'font-medium hover:text-indigo-600',
                                           'text-slate-900' => $customer->status === 'active',
                                           'text-slate-400 line-through' => $customer->status !== 'active',
                                       ])>{{ $customer->name }}</a>
                                    @if ($customer->tax_id)
                                        <p class="text-xs text-slate-500">{{ $customer->tax_id }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $customer->customerType?->name ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden lg:table-cell">
                                    {{ $customer->phone ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums hidden lg:table-cell">
                                    @if (! $customer->credit_enabled)
                                        <span class="text-slate-400">Sin credito</span>
                                    @elseif ($customer->credit_limit > 0)
                                        {{ $currency?->symbol }}{{ number_format($customer->credit_limit, 2) }}
                                    @else
                                        <span class="text-slate-500">Sin limite</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-amber-600' => $customer->balance > 0,
                                        'text-slate-400' => $customer->balance <= 0,
                                    ])>{{ $currency?->symbol }}{{ number_format($customer->balance, 2) }}</span>
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('customers.show', ['customerId' => $customer->id]) }}"
                                       wire:navigate
                                       class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                        Ver
                                    </a>
                                    @can('customers.edit')
                                        <button wire:click="edit('{{ $customer->id }}')"
                                                class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                            Editar
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $customers->links() }}</div>
    @endif

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar cliente' : 'Nuevo cliente'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Maria Fernandez"
                         :error="$errors->first('name')" autofocus />

                <div class="grid grid-cols-2 gap-3">
                    <x-input label="Identificacion fiscal" wire:model="taxId" placeholder="Opcional"
                             :error="$errors->first('taxId')" />
                    <x-input label="Telefono" wire:model="phone" placeholder="Opcional" inputmode="tel"
                             :error="$errors->first('phone')" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-input label="WhatsApp" wire:model="whatsapp" placeholder="Opcional" inputmode="tel"
                             :error="$errors->first('whatsapp')" />
                    <x-input label="Correo" type="email" wire:model="email" placeholder="Opcional"
                             :error="$errors->first('email')" />
                </div>

                <x-input label="Direccion" wire:model="address" placeholder="Opcional"
                         :error="$errors->first('address')" />

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Tipo de cliente</label>
                        <select wire:model="customerTypeId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin tipo</option>
                            @foreach ($customerTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Lista de precios</label>
                        <select wire:model="priceListId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">La de su tipo</option>
                            @foreach ($priceLists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-100">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.live="creditEnabled"
                               class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-900">Le vendo a credito</span>
                            <span class="block text-xs text-slate-500">
                                Podra llevarse mercancia y pagar despues.
                            </span>
                        </span>
                    </label>

                    @if ($creditEnabled)
                        <div class="grid grid-cols-2 gap-3 mt-4">
                            <x-input label="Limite de credito" type="number" step="0.01" min="0"
                                     wire:model="creditLimit" inputmode="decimal"
                                     hint="0 = sin limite"
                                     :error="$errors->first('creditLimit')" />
                            <x-input label="Dias de plazo" type="number" min="0" max="365"
                                     wire:model="creditDays" inputmode="numeric"
                                     :error="$errors->first('creditDays')" />
                        </div>
                    @endif
                </div>

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
