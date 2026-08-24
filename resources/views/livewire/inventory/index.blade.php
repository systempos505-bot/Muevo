<div>
    <x-page-header title="Inventario" subtitle="Existencias por sucursal" />

    {{-- Filtros --}}
    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por nombre o SKU"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="branchId"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todas las sucursales</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="filter"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="all">Todo</option>
            <option value="low">Stock bajo</option>
            <option value="out">Agotados</option>
        </select>
    </div>

    @if ($rows->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">▣</p>
            <p class="mt-3 font-medium text-slate-900">
                @if ($filter === 'low')
                    Nada por reponer
                @elseif ($filter === 'out')
                    Ningun producto agotado
                @else
                    Sin existencias registradas
                @endif
            </p>
            <p class="mt-1 text-sm text-slate-500">
                @if ($filter === 'all')
                    Crea productos con inventario inicial o registra una entrada.
                @else
                    Todo el inventario esta en orden.
                @endif
            </p>
        </x-card>
    @else

        {{-- Tarjetas: celular --}}
        <div class="space-y-2 lg:hidden">
            @foreach ($rows as $row)
                <div class="bg-white rounded-xl border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900 truncate">{{ $row->product->name }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $row->product->sku }} · {{ $row->branch->name }}
                            </p>
                        </div>
                        <p @class([
                            'font-semibold tabular-nums shrink-0',
                            'text-rose-600' => $row->quantity <= 0,
                            'text-amber-600' => $row->quantity > 0 && $row->quantity <= $row->effectiveMinStock() && $row->effectiveMinStock() > 0,
                            'text-slate-900' => $row->quantity > 0 && ($row->effectiveMinStock() <= 0 || $row->quantity > $row->effectiveMinStock()),
                        ])>
                            {{ rtrim(rtrim(number_format($row->quantity, 3), '0'), '.') }}
                            <span class="text-xs font-normal text-slate-400">
                                {{ $row->product->baseUnit?->code }}
                            </span>
                        </p>
                    </div>

                    <div class="flex items-center justify-between gap-2 mt-3">
                        <p class="text-xs text-slate-500">
                            Costo {{ $currency?->symbol }}{{ number_format($row->avg_cost, 2) }}
                            · Valor {{ $currency?->symbol }}{{ number_format($row->value(), 2) }}
                        </p>
                        <div class="flex gap-1 shrink-0">
                            <a href="{{ route('inventory.kardex', $row->product_id) }}" wire:navigate
                               class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                Kardex
                            </a>
                            @can('inventory.adjust')
                                <button wire:click="openAdjust('{{ $row->product_id }}', '{{ $row->branch_id }}')"
                                        class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                    Ajustar
                                </button>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Tabla: escritorio --}}
        <x-card flush class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Producto</th>
                            <th class="px-5 py-3 font-medium">Sucursal</th>
                            <th class="px-5 py-3 font-medium text-right">Existencia</th>
                            <th class="px-5 py-3 font-medium text-right">Minimo</th>
                            <th class="px-5 py-3 font-medium text-right">Costo prom.</th>
                            <th class="px-5 py-3 font-medium text-right">Valor</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('products.edit', $row->product_id) }}" wire:navigate
                                       class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $row->product->name }}
                                    </a>
                                    <p class="text-xs text-slate-500">
                                        {{ $row->product->sku }}
                                        @if ($row->variant) · {{ $row->variant->name }} @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $row->branch->name }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-rose-600' => $row->quantity <= 0,
                                        'text-amber-600' => $row->quantity > 0 && $row->quantity <= $row->effectiveMinStock() && $row->effectiveMinStock() > 0,
                                        'text-slate-700' => $row->quantity > 0 && ($row->effectiveMinStock() <= 0 || $row->quantity > $row->effectiveMinStock()),
                                    ])>{{ rtrim(rtrim(number_format($row->quantity, 3), '0'), '.') }}</span>
                                    <span class="text-xs text-slate-400">{{ $row->product->baseUnit?->code }}</span>
                                </td>
                                <td class="px-5 py-3 text-right text-slate-500 tabular-nums">
                                    {{ $row->effectiveMinStock() > 0
                                        ? rtrim(rtrim(number_format($row->effectiveMinStock(), 3), '0'), '.')
                                        : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($row->avg_cost, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($row->value(), 2) }}
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('inventory.kardex', $row->product_id) }}" wire:navigate
                                       class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                        Kardex
                                    </a>
                                    @can('inventory.adjust')
                                        <button wire:click="openAdjust('{{ $row->product_id }}', '{{ $row->branch_id }}')"
                                                class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                            Ajustar
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif

    @if ($showAdjust)
        <x-modal title="Ajustar inventario" wire="showAdjust">
            <form wire:submit="saveAdjust" class="space-y-4">
                <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="font-medium text-slate-900">{{ $adjustProductName }}</p>
                    <p class="text-sm text-slate-500 mt-0.5">
                        Existencia actual:
                        <span class="font-medium tabular-nums">
                            {{ rtrim(rtrim(number_format($adjustCurrent, 3), '0'), '.') }}
                        </span>
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="$set('adjustMode', 'delta')"
                        @class([
                            'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                            'border-indigo-500 bg-indigo-50 text-indigo-700' => $adjustMode === 'delta',
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $adjustMode !== 'delta',
                        ])>
                        Sumar o restar
                    </button>
                    <button type="button" wire:click="$set('adjustMode', 'set')"
                        @class([
                            'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                            'border-indigo-500 bg-indigo-50 text-indigo-700' => $adjustMode === 'set',
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $adjustMode !== 'set',
                        ])>
                        Dejar en
                    </button>
                </div>

                <x-input
                    :label="$adjustMode === 'set' ? 'Cantidad contada' : 'Cantidad a mover'"
                    type="number" step="0.001" wire:model="adjustQuantity" inputmode="decimal"
                    :hint="$adjustMode === 'set'
                        ? 'El sistema calcula la diferencia solo.'
                        : 'Positiva para entrada, negativa para salida.'"
                    :error="$errors->first('adjustQuantity')" autofocus />

                @if ($adjustMode === 'delta')
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Tipo de movimiento</label>
                        <select wire:model="adjustType"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            @foreach ($adjustTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <x-input label="Motivo" wire:model="adjustReason"
                         placeholder="Producto danado en bodega"
                         hint="Queda en el kardex para siempre."
                         :error="$errors->first('adjustReason')" />

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showAdjust', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Aplicar ajuste</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
