<div>
    <x-page-header title="Nueva compra" :subtitle="$this->totals['items'] . ' producto(s)'">
        <x-slot:actions>
            <a href="{{ route('purchases') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Cancelar</x-button>
            </a>
            <x-button size="sm" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Registrar compra</span>
                <span wire:loading wire:target="save">Registrando...</span>
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3">
            <ul class="text-sm text-rose-700 space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ========== Datos de la compra ========== --}}
        <x-card title="Datos de la compra" class="lg:col-span-1 lg:order-2">
            <div class="space-y-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Proveedor</label>
                    <select wire:model.live="supplierId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Sin proveedor</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplierId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <x-input label="Numero de factura" wire:model="invoiceNumber"
                         placeholder="Opcional"
                         hint="El del documento del proveedor"
                         :error="$errors->first('invoiceNumber')" />

                @if ($branches->count() > 1)
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Entra a</label>
                        <select wire:model="branchId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Forma de pago</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="$set('paymentType', 'cash')"
                            @class([
                                'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                                'border-indigo-500 bg-indigo-50 text-indigo-700' => $paymentType === 'cash',
                                'border-slate-300 text-slate-700 hover:bg-slate-50' => $paymentType !== 'cash',
                            ])>Contado</button>
                        <button type="button" wire:click="$set('paymentType', 'credit')"
                            @class([
                                'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                                'border-indigo-500 bg-indigo-50 text-indigo-700' => $paymentType === 'credit',
                                'border-slate-300 text-slate-700 hover:bg-slate-50' => $paymentType !== 'credit',
                            ])>Credito</button>
                    </div>
                </div>

                @if ($paymentType === 'credit')
                    <x-input label="Se paga el" type="date" wire:model="dueDate"
                             :error="$errors->first('dueDate')" />
                @else
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Se paga con</label>
                        <select wire:model="paymentMethodId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <label class="flex items-start gap-3 cursor-pointer pt-2 border-t border-slate-100">
                    <input type="checkbox" wire:model="updatesCost"
                           class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Actualizar costos</span>
                        <span class="block text-xs text-slate-500">
                            Deja el costo nuevo en cada producto y recalcula los precios
                            de las listas que trabajan por margen.
                        </span>
                    </span>
                </label>

                <x-input label="Notas" wire:model="notes" placeholder="Opcional" />

                {{-- Totales --}}
                <div class="pt-3 border-t border-slate-200 space-y-1 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal</span>
                        <span class="tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->totals['subtotal'], 2) }}
                        </span>
                    </div>
                    @if ($this->totals['discount'] > 0)
                        <div class="flex justify-between text-emerald-700">
                            <span>Descuento</span>
                            <span class="tabular-nums">
                                −{{ $currency?->symbol }}{{ number_format($this->totals['discount'], 2) }}
                            </span>
                        </div>
                    @endif
                    <div class="flex justify-between text-slate-600">
                        <span>Impuesto</span>
                        <span class="tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->totals['tax'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-baseline pt-2 border-t border-slate-200">
                        <span class="font-medium text-slate-700">Total</span>
                        <span class="text-2xl font-bold tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->totals['total'], 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- ========== Productos ========== --}}
        <div class="lg:col-span-2 lg:order-1 space-y-3">

            <form wire:submit="submitSearch" class="relative">
                <input type="text" wire:model.live.debounce.250ms="search"
                       placeholder="Escanea o escribe el producto que llego"
                       autocomplete="off" autofocus
                       class="w-full rounded-xl border-2 border-slate-300 pl-11 pr-3 py-3.5 text-base
                              placeholder:text-slate-400 focus:outline-none focus:border-indigo-500">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">⌕</span>
            </form>

            @if (mb_strlen(trim($search)) >= 2 && $this->results->isNotEmpty())
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach ($this->results as $product)
                        <button type="button" wire:click="addProduct('{{ $product->id }}')"
                                wire:key="res-{{ $product->id }}"
                                class="text-left p-3 rounded-xl border border-slate-200 bg-white
                                       hover:border-indigo-400 transition">
                            <p class="font-medium text-slate-900 text-sm line-clamp-2">{{ $product->name }}</p>
                            <p class="text-xs text-slate-500 mt-1">{{ $product->sku }}</p>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($lines === [])
                <x-card class="text-center py-12">
                    <p class="text-4xl">▽</p>
                    <p class="mt-3 font-medium text-slate-900">Sin productos</p>
                    <p class="mt-1 text-sm text-slate-500">
                        Escanea o busca lo que llego del proveedor.
                    </p>
                </x-card>
            @else
                <div class="space-y-2">
                    @foreach ($lines as $key => $line)
                        <x-card wire:key="line-{{ $key }}">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900">{{ $line['name'] }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $line['sku'] }} · se compra por {{ $line['unit_label'] }}
                                        @if ($line['unit_factor'] > 1)
                                            (x{{ rtrim(rtrim(number_format($line['unit_factor'], 2), '0'), '.') }})
                                        @endif
                                    </p>
                                </div>
                                <button type="button" wire:click="removeLine('{{ $key }}')"
                                        class="text-slate-300 hover:text-rose-600 text-lg leading-none">&times;</button>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="space-y-1">
                                    <label class="block text-xs text-slate-500">Cantidad</label>
                                    <input type="number" step="0.001" min="0" inputmode="decimal"
                                           wire:model.live.debounce.500ms="lines.{{ $key }}.quantity"
                                           class="w-full rounded-lg border border-slate-300 px-3 py-2
                                                  text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <div class="space-y-1">
                                    <label class="block text-xs text-slate-500">
                                        Costo por {{ $line['unit_label'] }}
                                    </label>
                                    <input type="number" step="0.0001" min="0" inputmode="decimal"
                                           wire:model.live.debounce.500ms="lines.{{ $key }}.unit_cost"
                                           class="w-full rounded-lg border border-slate-300 px-3 py-2
                                                  text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <div class="space-y-1">
                                    <label class="block text-xs text-slate-500">Descuento</label>
                                    <input type="number" step="0.01" min="0" inputmode="decimal"
                                           wire:model.live.debounce.500ms="lines.{{ $key }}.discount"
                                           class="w-full rounded-lg border border-slate-300 px-3 py-2
                                                  text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                                </div>

                                <div class="space-y-1">
                                    <label class="block text-xs text-slate-500">Total</label>
                                    <p class="px-3 py-2 rounded-lg bg-slate-50 text-right tabular-nums font-medium">
                                        {{ $currency?->symbol }}{{ number_format($line['total'], 2) }}
                                    </p>
                                </div>
                            </div>

                            {{-- Se muestra a cuanto sale la pieza: es el numero
                                 que va a alimentar el margen de venta. --}}
                            @if ($line['unit_factor'] > 1)
                                <p class="mt-2 text-xs text-slate-500">
                                    Sale a
                                    <span class="font-medium text-slate-700 tabular-nums">
                                        {{ $currency?->symbol }}{{ number_format($this->baseCostFor($line), 4) }}
                                    </span>
                                    por unidad base
                                </p>
                            @endif

                            @if ($line['track_lots'] || $line['track_expiry'])
                                <div class="grid grid-cols-2 gap-3 mt-3 pt-3 border-t border-slate-100">
                                    <div class="space-y-1">
                                        <label class="block text-xs text-slate-500">Numero de lote</label>
                                        <input type="text" wire:model="lines.{{ $key }}.lot_number"
                                               placeholder="L-2027-A"
                                               class="w-full rounded-lg border border-slate-300 px-3 py-2
                                                      focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    @if ($line['track_expiry'])
                                        <div class="space-y-1">
                                            <label class="block text-xs text-slate-500">Vence el</label>
                                            <input type="date" wire:model="lines.{{ $key }}.expiry_date"
                                                   class="w-full rounded-lg border border-slate-300 px-3 py-2
                                                          focus:ring-2 focus:ring-indigo-500">
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </x-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
