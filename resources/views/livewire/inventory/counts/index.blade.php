@php $statuses = \App\Models\StockCount::STATUSES; @endphp

<div>
    <x-page-header title="Inventario fisico" subtitle="Contar lo que de verdad hay y ajustar la diferencia">
        <x-slot:actions>
            @can('inventory.count')
                <x-button size="sm" wire:click="create">+ Conteo</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los estados</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if ($counts->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">▣</p>
            <p class="mt-3 font-medium text-slate-900">Todavia no hay inventarios fisicos</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                Un conteo trae el catalogo con lo que el sistema cree que hay, y solo
                cambia la existencia cuando decides aplicarlo.
            </p>
            @can('inventory.count')
                <div class="mt-5">
                    <x-button wire:click="create">Abrir el primero</x-button>
                </div>
            @endcan
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium">Sucursal</th>
                            <th class="px-5 py-3 font-medium text-right hidden sm:table-cell">Productos</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Quien lo abrio</th>
                            <th class="px-5 py-3 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($counts as $count)
                            <tr class="hover:bg-slate-50" wire:key="count-{{ $count->id }}">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('stock-counts.show', $count) }}" wire:navigate
                                       class="text-indigo-600 hover:underline font-medium">
                                        {{ $count->folio }}
                                    </a>
                                    <p class="text-xs text-slate-400">
                                        {{ $count->created_at->format('d/m/Y') }}
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-slate-700">{{ $count->branch?->name }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 hidden sm:table-cell">
                                    {{ $count->items_count }}
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $count->creator?->name }}
                                </td>
                                <td class="px-5 py-3">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs whitespace-nowrap',
                                        'bg-amber-100 text-amber-700' => $count->isOpen(),
                                        'bg-emerald-100 text-emerald-700' => $count->isApplied(),
                                        'bg-slate-100 text-slate-600' => $count->isCancelled(),
                                    ])>{{ $count->statusLabel() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $counts->links() }}</div>
    @endif

    {{-- ==================== Alta ==================== --}}
    @if ($showForm)
        <x-modal title="Nuevo inventario fisico" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Sucursal</label>
                    <select wire:model="branchId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                   focus:ring-2 focus:ring-indigo-500">
                        @foreach ($this->branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branchId')
                        <p class="text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">
                        Categoria
                        <span class="font-normal text-slate-500">(opcional)</span>
                    </label>
                    <select wire:model="categoryId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                   focus:ring-2 focus:ring-indigo-500">
                        <option value="">Todo el catalogo</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500">
                        Para no contar la tienda entera de una vez, elige una sola categoria.
                    </p>
                </div>

                <x-input label="Nota (opcional)" wire:model="notes"
                         placeholder="Conteo mensual de ropa"
                         :error="$errors->first('notes')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Abrir conteo</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
