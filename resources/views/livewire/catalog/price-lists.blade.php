<div>
    <x-page-header title="Catalogo" subtitle="Listas de precios">
        <x-slot:actions>
            @can('products.edit')
                <x-button size="sm" wire:click="create">+ Lista</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.catalog-tabs')

    <div class="mb-4 rounded-lg bg-slate-100 border border-slate-200 px-4 py-3">
        <p class="text-sm text-slate-600">
            Cada producto lleva un precio en cada lista. La de <strong>mostrador</strong> es la
            que usa la caja cuando el cliente no tiene una asignada.
            Puedes tener hasta {{ \App\Models\PriceList::MAX_PER_TENANT }}.
        </p>
    </div>

    <div class="space-y-2">
        @foreach ($lists as $list)
            <x-card flush>
                <div class="flex items-center gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p @class([
                                'font-medium truncate',
                                'text-slate-900' => $list->status === 'active',
                                'text-slate-400 line-through' => $list->status !== 'active',
                            ])>{{ $list->name }}</p>

                            @if ($list->is_default)
                                <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-xs font-medium">
                                    Mostrador
                                </span>
                            @endif

                            @if ($list->usesMargin())
                                <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-medium">
                                    Costo + {{ rtrim(rtrim(number_format($list->margin_percent, 2), '0'), '.') }}%
                                </span>
                            @endif
                        </div>

                        <p class="text-xs text-slate-500 mt-0.5">
                            {{ $list->prices_count }} precio(s) capturados
                            @if ($list->usesMargin())
                                · se recalculan al cambiar el costo
                            @endif
                        </p>
                    </div>

                    @can('products.edit')
                        <div class="flex items-center gap-1 shrink-0 flex-wrap justify-end">
                            @unless ($list->is_default)
                                <button wire:click="makeDefault('{{ $list->id }}')"
                                        class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                    Usar en caja
                                </button>
                            @endunless

                            <button wire:click="edit('{{ $list->id }}')"
                                    class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                Editar
                            </button>

                            <button wire:click="toggleStatus('{{ $list->id }}')"
                                    class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                {{ $list->status === 'active' ? 'Desactivar' : 'Activar' }}
                            </button>

                            <button wire:click="delete('{{ $list->id }}')"
                                    wire:confirm="Eliminar la lista {{ $list->name }}?"
                                    class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                Borrar
                            </button>
                        </div>
                    @endcan
                </div>
            </x-card>
        @endforeach
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar lista' : 'Nueva lista de precios'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Mayoreo"
                         :error="$errors->first('name')" autofocus />

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700">Como se fija el precio</label>

                    <label @class([
                        'flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition',
                        'border-indigo-500 bg-indigo-50' => $pricingMode === 'manual',
                        'border-slate-300 hover:bg-slate-50' => $pricingMode !== 'manual',
                    ])>
                        <input type="radio" wire:model.live="pricingMode" value="manual"
                               class="mt-1 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-900">A mano</span>
                            <span class="block text-xs text-slate-500">
                                Capturas el precio producto por producto.
                            </span>
                        </span>
                    </label>

                    <label @class([
                        'flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition',
                        'border-indigo-500 bg-indigo-50' => $pricingMode === 'margin',
                        'border-slate-300 hover:bg-slate-50' => $pricingMode !== 'margin',
                    ])>
                        <input type="radio" wire:model.live="pricingMode" value="margin"
                               class="mt-1 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-900">Por margen</span>
                            <span class="block text-xs text-slate-500">
                                El precio sale del costo y se recalcula solo cuando el costo cambia.
                            </span>
                        </span>
                    </label>
                </div>

                @if ($pricingMode === 'margin')
                    <x-input label="Margen sobre el costo (%)" type="number" step="0.01" min="0"
                             wire:model="marginPercent" inputmode="decimal" placeholder="30"
                             hint="Un precio capturado a mano no se sobrescribe."
                             :error="$errors->first('marginPercent')" />
                @endif

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
