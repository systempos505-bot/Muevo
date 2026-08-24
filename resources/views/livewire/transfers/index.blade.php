@php $statuses = \App\Models\StockTransfer::STATUSES; @endphp

<div>
    <x-page-header title="Traspasos"
                   :subtitle="$this->pending . ' esperando salir o llegar'">
        <x-slot:actions>
            @can('inventory.adjust')
                <x-button size="sm" wire:click="create">+ Traspaso</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($this->branches->count() < 2)
        <x-card class="text-center py-16">
            <p class="text-4xl">⇄</p>
            <p class="mt-3 font-medium text-slate-900">Solo tienes una sucursal</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                Un traspaso mueve mercancia de una tienda a otra. Cuando abras la
                segunda, esta pantalla te va a servir.
            </p>
        </x-card>
    @else
        <div class="flex flex-col sm:flex-row gap-3 mb-4">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Buscar por folio o producto"
                   class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                          placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

            <select wire:model.live="status"
                    class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                <option value="">Todos</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if ($transfers->isEmpty())
            <x-card class="text-center py-16">
                <p class="text-4xl">⇄</p>
                <p class="mt-3 font-medium text-slate-900">Todavia no hay traspasos</p>
                <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                    La mercancia sale de una sucursal y llega a otra en dos pasos, para que
                    lo que va en camino no aparezca como existencia en ningun lado.
                </p>
                @can('inventory.adjust')
                    <div class="mt-5">
                        <x-button wire:click="create">Crear el primero</x-button>
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
                                <th class="px-5 py-3 font-medium">Ruta</th>
                                <th class="px-5 py-3 font-medium text-right hidden sm:table-cell">Productos</th>
                                <th class="px-5 py-3 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($transfers as $transfer)
                                <tr class="hover:bg-slate-50" wire:key="t-{{ $transfer->id }}">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('transfers.show', $transfer) }}" wire:navigate
                                           class="text-indigo-600 hover:underline font-medium">
                                            {{ $transfer->folio }}
                                        </a>
                                        <p class="text-xs text-slate-400">
                                            {{ $transfer->created_at->format('d/m/Y') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3 text-slate-700">
                                        {{ $transfer->fromBranch?->name }}
                                        <span class="text-slate-400 mx-1">→</span>
                                        {{ $transfer->toBranch?->name }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600 hidden sm:table-cell">
                                        {{ $transfer->items->count() }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <span @class([
                                            'px-2 py-0.5 rounded text-xs whitespace-nowrap',
                                            'bg-slate-100 text-slate-600' => $transfer->isDraft(),
                                            'bg-amber-100 text-amber-700' => $transfer->isInTransit(),
                                            'bg-emerald-100 text-emerald-700' => $transfer->isReceived(),
                                            'bg-rose-100 text-rose-700' => $transfer->isCancelled(),
                                        ])>{{ $transfer->statusLabel() }}</span>

                                        @if ($transfer->isReceived() && $transfer->shortfall() > 0)
                                            <span class="ml-1 px-2 py-0.5 rounded bg-rose-100 text-rose-700 text-xs">
                                                falto {{ rtrim(rtrim(number_format($transfer->shortfall(), 3), '0'), '.') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            <div class="mt-4">{{ $transfers->links() }}</div>
        @endif
    @endif

    {{-- ==================== Alta ==================== --}}
    @if ($showForm)
        <x-modal title="Nuevo traspaso" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Sale de</label>
                        <select wire:model.live="fromBranchId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                       focus:ring-2 focus:ring-indigo-500">
                            @foreach ($this->branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('fromBranchId')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Va a</label>
                        <select wire:model.live="toBranchId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                       focus:ring-2 focus:ring-indigo-500">
                            <option value="">Elige la sucursal</option>
                            @foreach ($this->branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('toBranchId')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Que se manda</label>
                    <input type="search" wire:model.live.debounce.300ms="productSearch"
                           placeholder="Buscar producto por nombre, SKU o codigo"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                  placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

                    @if ($this->results->isNotEmpty())
                        <ul class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-44 overflow-y-auto">
                            @foreach ($this->results as $product)
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
                    @endif
                </div>

                @if ($lines !== [])
                    <div class="space-y-2">
                        @foreach ($lines as $key => $line)
                            @php $available = $this->availability[$line['product_id']] ?? 0; @endphp
                            <div wire:key="line-{{ $key }}"
                                 class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-900 truncate">{{ $line['name'] }}</p>
                                    {{-- La existencia del origen se ve al lado: nadie
                                         deberia escribir de memoria un numero que la
                                         tienda no tiene. --}}
                                    <p @class([
                                        'text-xs tabular-nums',
                                        'text-rose-600' => $line['quantity'] > $available,
                                        'text-slate-500' => $line['quantity'] <= $available,
                                    ])>
                                        hay {{ rtrim(rtrim(number_format($available, 3), '0'), '.') }}
                                        {{ $line['unit'] }} en el origen
                                    </p>
                                </div>

                                <div class="flex items-center gap-1.5 shrink-0">
                                    <input type="number" step="0.001" min="0" inputmode="decimal"
                                           wire:model.live.debounce.500ms="lines.{{ $key }}.quantity"
                                           class="w-20 text-center rounded-lg border border-slate-300 py-1.5 text-sm
                                                  tabular-nums focus:ring-2 focus:ring-indigo-500">
                                    <button type="button" wire:click="removeLine('{{ $key }}')"
                                            class="text-slate-300 hover:text-rose-600 text-lg leading-none">&times;</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @error('lines')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <x-input label="Nota (opcional)" wire:model="notes"
                         placeholder="Va con el repartidor de la tarde"
                         :error="$errors->first('notes')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Crear traspaso</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
