<div>
    <x-page-header :title="$quote ? 'Editar ' . $quote->folio : 'Nueva cotizacion'"
                   subtitle="Un precio por escrito, con fecha hasta la que se sostiene">
        <x-slot:actions>
            <a href="{{ route('quotes') }}" wire:navigate
               class="inline-flex items-center justify-center rounded-lg font-medium transition
                      px-3 py-1.5 text-sm bg-white text-slate-700 border border-slate-300 hover:bg-slate-50">
                Cancelar
            </a>
            <x-button size="sm" wire:click="save" wire:loading.attr="disabled">Guardar</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid lg:grid-cols-3 gap-5 items-start">
        {{-- ---------- Productos ---------- --}}
        <div class="lg:col-span-2 space-y-5">
            <x-card title="Productos">
                <form wire:submit="submitSearch" class="relative">
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Buscar por nombre, SKU o codigo de barras"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2.5
                                  placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

                    @if ($this->results->isNotEmpty())
                        <ul class="absolute z-20 mt-1 w-full bg-white border border-slate-200 rounded-lg
                                   shadow-lg max-h-72 overflow-y-auto">
                            @foreach ($this->results as $product)
                                <li wire:key="r-{{ $product->id }}">
                                    <button type="button" wire:click="addProduct('{{ $product->id }}')"
                                            class="w-full text-left px-4 py-2.5 hover:bg-slate-50 flex justify-between gap-3">
                                        <span class="min-w-0">
                                            <span class="block truncate text-slate-900">{{ $product->name }}</span>
                                            <span class="block text-xs text-slate-400">{{ $product->sku }}</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </form>

                @error('lines')
                    <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                @if ($lines === [])
                    <p class="mt-6 text-center text-sm text-slate-500 py-8">
                        Busca un producto arriba para empezar a cotizar.
                    </p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-slate-500">
                                <tr>
                                    <th class="py-2 font-medium">Producto</th>
                                    <th class="py-2 font-medium text-right w-24">Cantidad</th>
                                    <th class="py-2 font-medium text-right w-28">Precio</th>
                                    <th class="py-2 font-medium text-right w-24">Descuento</th>
                                    <th class="py-2 font-medium text-right w-28">Total</th>
                                    <th class="w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($lines as $key => $line)
                                    <tr wire:key="l-{{ $key }}">
                                        <td class="py-2.5 pr-3">
                                            <span class="block text-slate-900">{{ $line['name'] }}</span>
                                            <span class="block text-xs text-slate-400">
                                                {{ $line['sku'] }}
                                                @if ($line['unit_label']) · {{ $line['unit_label'] }} @endif
                                            </span>
                                        </td>
                                        <td class="py-2.5 text-right">
                                            {{-- El .live va antes de .blur a proposito: sin el,
                                                 Livewire solo sincroniza del lado del cliente y
                                                 nunca manda la red, asi que el total de la linea
                                                 y el resumen se quedarian congelados. --}}
                                            <input type="number" step="0.001" min="0.001" inputmode="decimal"
                                                   wire:model.live.blur="lines.{{ $key }}.quantity"
                                                   class="w-20 text-right rounded-lg border border-slate-300 py-1.5 px-2
                                                          tabular-nums focus:ring-2 focus:ring-indigo-500">
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                                   wire:model.live.blur="lines.{{ $key }}.unit_price"
                                                   class="w-24 text-right rounded-lg border border-slate-300 py-1.5 px-2
                                                          tabular-nums focus:ring-2 focus:ring-indigo-500">
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                                   wire:model.live.blur="lines.{{ $key }}.discount"
                                                   class="w-20 text-right rounded-lg border border-slate-300 py-1.5 px-2
                                                          tabular-nums focus:ring-2 focus:ring-indigo-500">
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums font-medium text-slate-900">
                                            {{ $currency?->symbol }}{{ number_format($line['total'], 2) }}
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <button type="button" wire:click="removeLine('{{ $key }}')"
                                                    class="text-slate-400 hover:text-rose-600 text-lg leading-none">&times;</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        {{-- ---------- Datos y totales ---------- --}}
        <div class="space-y-5">
            <x-card title="Para quien">
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Cliente registrado</label>
                        <select wire:model.live="customerId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin registrar</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-500">
                            No hace falta. La mayoria de las cotizaciones se piden antes
                            de que alguien sea cliente.
                        </p>
                    </div>

                    <x-input label="A nombre de" wire:model="customerName"
                             placeholder="Nombre de quien pide el precio"
                             :error="$errors->first('customerName')" />

                    <x-input label="Telefono" wire:model="customerPhone"
                             placeholder="Para mandarsela despues"
                             :error="$errors->first('customerPhone')" />
                </div>
            </x-card>

            <x-card title="Condiciones">
                <div class="space-y-4">
                    <x-input type="date" label="Vigente hasta" wire:model="validUntil"
                             hint="Despues de esta fecha el precio deja de estar comprometido."
                             :error="$errors->first('validUntil')" />

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Lista de precios</label>
                        <select wire:model.live="priceListId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            @foreach ($priceLists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($branches->count() > 1)
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-slate-700">Sucursal</label>
                            <select wire:model="branchId"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Notas</label>
                        <textarea wire:model="notes" rows="3"
                                  placeholder="Condiciones de entrega, forma de pago..."
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5
                                         placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                </div>
            </x-card>

            <x-card title="Resumen">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Productos</dt>
                        <dd class="tabular-nums text-slate-900">{{ $this->totals['items'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($this->totals['subtotal'], 2) }}
                        </dd>
                    </div>
                    @if ($this->totals['discount'] > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Descuento</dt>
                            <dd class="tabular-nums text-emerald-700">
                                −{{ $currency?->symbol }}{{ number_format($this->totals['discount'], 2) }}
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Impuesto</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($this->totals['tax'], 2) }}
                        </dd>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-slate-100 text-base font-semibold">
                        <dt class="text-slate-900">Total</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($this->totals['total'], 2) }}
                        </dd>
                    </div>
                </dl>

                <x-button class="w-full mt-4" wire:click="save" wire:loading.attr="disabled">
                    {{ $quote ? 'Guardar cambios' : 'Crear cotizacion' }}
                </x-button>
            </x-card>
        </div>
    </div>
</div>
