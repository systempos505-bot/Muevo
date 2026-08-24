<div>
    <x-page-header title="Catalogo" subtitle="Unidades de medida">
        <x-slot:actions>
            @can('products.edit')
                <x-button size="sm" wire:click="create">+ Unidad</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.catalog-tabs')

    <div class="mb-4 rounded-lg bg-slate-100 border border-slate-200 px-4 py-3">
        <p class="text-sm text-slate-600">
            Aqui defines <strong>que unidades existen</strong>. Cuantas unidades base trae cada
            una se decide en cada producto, porque una caja de guantes y una de jarabe no traen
            lo mismo.
        </p>
    </div>

    <x-card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-5 py-3 font-medium">Codigo</th>
                        <th class="px-5 py-3 font-medium">Nombre</th>
                        <th class="px-5 py-3 font-medium">Plural</th>
                        <th class="px-5 py-3 font-medium">Decimales</th>
                        <th class="px-5 py-3 font-medium text-right">Productos</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($units as $unit)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-xs font-medium">
                                    {{ $unit->code }}
                                </span>
                            </td>
                            <td class="px-5 py-3 font-medium text-slate-900">{{ $unit->name }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $unit->plural_name ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                {{ $unit->allows_decimals ? 'Si' : 'No' }}
                            </td>
                            <td class="px-5 py-3 text-right text-slate-600 tabular-nums">
                                {{ $unit->products_count }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                @can('products.edit')
                                    <button wire:click="edit('{{ $unit->id }}')"
                                            class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                        Editar
                                    </button>
                                    <button wire:click="delete('{{ $unit->id }}')"
                                            wire:confirm="Eliminar la unidad {{ $unit->name }}?"
                                            class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                        Borrar
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar unidad' : 'Nueva unidad'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <x-input label="Codigo" wire:model="code" placeholder="CJA" maxlength="10"
                             :error="$errors->first('code')" autofocus />
                    <div class="col-span-2">
                        <x-input label="Nombre" wire:model="name" placeholder="Caja"
                                 :error="$errors->first('name')" />
                    </div>
                </div>

                <x-input label="Plural" wire:model="pluralName" placeholder="Cajas"
                         hint="Opcional. Se usa al mostrar cantidades."
                         :error="$errors->first('pluralName')" />

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model="allowsDecimals"
                           class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Permite decimales</span>
                        <span class="block text-xs text-slate-500">
                            Actívalo para peso o volumen, donde se vende 1.5 o 0.250.
                        </span>
                    </span>
                </label>

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
