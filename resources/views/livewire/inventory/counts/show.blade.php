@php
    $symbol = $currency?->symbol ?? '$';
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.') ?: '0';
@endphp

<div>
    <x-page-header :title="'Inventario fisico ' . $count->folio" :subtitle="$count->branch?->name">
        <x-slot:actions>
            <a href="{{ route('stock-counts') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>

            @can('inventory.count')
                @if ($count->isOpen())
                    <x-button variant="secondary" size="sm" wire:click="$set('showAddProduct', true)">
                        + Producto
                    </x-button>
                    <x-button variant="secondary" size="sm" wire:click="saveProgress">
                        Guardar avance
                    </x-button>
                    <x-button size="sm" wire:click="apply"
                              wire:confirm="Esto va a ajustar la existencia de las lineas con diferencia. ¿Aplicar el conteo?">
                        Aplicar conteo
                    </x-button>
                    <x-button variant="danger" size="sm" wire:click="$set('showCancel', true)">
                        Cancelar
                    </x-button>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($count->isApplied())
        <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3">
            <p class="text-sm text-emerald-800">
                Aplicado el {{ $count->applied_at?->format('d/m/Y H:i') }}
                por {{ $count->applier?->name }}. La existencia ya quedo ajustada.
            </p>
        </div>
    @elseif ($count->isCancelled())
        <div class="mb-4 rounded-lg bg-slate-100 border border-slate-200 px-4 py-3">
            <p class="text-sm text-slate-600">Este conteo se cerro sin aplicar. No movio nada.</p>
        </div>
    @endif

    {{-- Resumen --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Productos</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $this->summary['lines'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Con diferencia</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $this->summary['differences'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Sobrante a costo</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">
                {{ $symbol }}{{ number_format($this->summary['overage'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Faltante a costo</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600">
                {{ $symbol }}{{ number_format($this->summary['shortage'], 2) }}
            </p>
        </x-card>
    </div>

    <input type="search" wire:model.live.debounce.300ms="search"
           placeholder="Buscar por nombre o SKU en este conteo"
           class="w-full mb-4 rounded-lg border border-slate-300 px-3 py-2.5
                  placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

    <x-card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-5 py-3 font-medium">Producto</th>
                        <th class="px-5 py-3 font-medium text-right">Sistema</th>
                        <th class="px-5 py-3 font-medium text-right">Contado</th>
                        <th class="px-5 py-3 font-medium text-right">Diferencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->visibleItems as $item)
                        @php $diff = round(($counted[$item->id] ?? $item->counted_qty) - $item->system_qty, 3); @endphp
                        <tr wire:key="item-{{ $item->id }}" class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <p class="text-slate-900">{{ $item->product?->name ?? 'Producto eliminado' }}</p>
                                @if ($item->product?->sku)
                                    <p class="text-xs text-slate-400">{{ $item->product->sku }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-500">
                                {{ $fmt($item->system_qty) }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($count->isOpen())
                                    {{-- El .live va antes de .blur a proposito: sin el,
                                         Livewire solo sincroniza del lado del cliente y
                                         nunca manda la red, asi que el resumen de arriba
                                         se quedaria congelado hasta la siguiente accion. --}}
                                    <input type="number" step="0.001" min="0" inputmode="decimal"
                                           wire:model.live.blur="counted.{{ $item->id }}"
                                           class="w-24 text-right rounded-lg border border-slate-300 py-1.5 px-2 text-sm
                                                  tabular-nums focus:ring-2 focus:ring-indigo-500">
                                @else
                                    <span class="tabular-nums text-slate-700">{{ $fmt($item->counted_qty) }}</span>
                                @endif
                            </td>
                            <td @class([
                                'px-5 py-3 text-right tabular-nums font-medium',
                                'text-emerald-600' => $diff > 0,
                                'text-rose-600' => $diff < 0,
                                'text-slate-400' => $diff == 0,
                            ])>
                                {{ $diff > 0 ? '+' : '' }}{{ $fmt($diff) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-sm text-slate-500">
                                Sin productos que coincidan con la busqueda.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($count->notes)
        <p class="mt-3 text-sm text-slate-500">{{ $count->notes }}</p>
    @endif

    {{-- ==================== Agregar producto ==================== --}}
    @if ($showAddProduct)
        <x-modal title="Agregar producto al conteo" wire="showAddProduct">
            <div class="space-y-3">
                <input type="search" wire:model.live.debounce.300ms="productSearch"
                       placeholder="Buscar por nombre, SKU o codigo"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                              placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

                @if ($this->productResults->isNotEmpty())
                    <ul class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-60 overflow-y-auto">
                        @foreach ($this->productResults as $product)
                            <li>
                                <button type="button" wire:click="addProduct('{{ $product->id }}')"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
                                    {{ $product->name }}
                                    @if ($product->sku)
                                        <span class="text-xs text-slate-400 ml-1">{{ $product->sku }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($productSearch)) >= 2)
                    <p class="text-sm text-slate-500 px-1">Sin resultados.</p>
                @endif
            </div>
        </x-modal>
    @endif

    {{-- ==================== Cancelacion ==================== --}}
    @if ($showCancel)
        <x-modal title="Cancelar conteo" wire="showCancel">
            <div class="space-y-4">
                <p class="text-sm text-slate-600">
                    El conteo se cierra sin aplicar. No movio ni va a mover ninguna
                    existencia; lo capturado hasta ahora se queda solo como registro.
                </p>

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showCancel', false)">Volver</x-button>
                    <x-button type="button" variant="danger" class="flex-1" wire:click="cancel">
                        Cancelar conteo
                    </x-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
