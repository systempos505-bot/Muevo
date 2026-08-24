<div class="lg:h-[calc(100vh-3rem)] lg:flex lg:gap-4">

    {{-- ================= IZQUIERDA: buscar y resultados ================= --}}
    <div class="lg:flex-1 lg:flex lg:flex-col lg:min-w-0">

        <div class="flex items-center gap-2 mb-3">
            <form wire:submit="submitSearch" class="flex-1 relative">
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Escanea o escribe el producto"
                    autocomplete="off"
                    autofocus
                    class="w-full rounded-xl border-2 border-slate-300 pl-11 pr-3 py-3.5 text-base
                           placeholder:text-slate-400 focus:outline-none focus:border-indigo-500"
                >
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">⌕</span>
            </form>

            @if ($this->heldSales->isNotEmpty())
                <button type="button" wire:click="$set('showHeld', true)"
                        class="relative px-3 py-3.5 rounded-xl border border-slate-300 bg-white
                               text-sm font-medium text-slate-700 hover:bg-slate-50">
                    En espera
                    <span class="absolute -top-1.5 -right-1.5 grid place-items-center w-5 h-5
                                 rounded-full bg-indigo-600 text-white text-[10px]">
                        {{ $this->heldSales->count() }}
                    </span>
                </button>
            @endif
        </div>

        {{-- Resultados de la busqueda --}}
        <div class="lg:flex-1 lg:overflow-y-auto">
            @if (mb_strlen(trim($search)) >= 2)
                @if ($this->results->isEmpty())
                    <x-card class="text-center py-10">
                        <p class="text-sm text-slate-500">Ningun producto coincide con esa busqueda.</p>
                    </x-card>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2">
                        @foreach ($this->results as $product)
                            <button type="button" wire:click="addProduct('{{ $product->id }}')"
                                    wire:key="res-{{ $product->id }}"
                                    class="text-left p-3 rounded-xl border border-slate-200 bg-white
                                           hover:border-indigo-400 hover:shadow-sm transition">
                                <p class="font-medium text-slate-900 text-sm line-clamp-2">
                                    {{ $product->name }}
                                </p>
                                <p class="text-xs text-slate-500 mt-1">{{ $product->sku }}</p>
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <x-card class="text-center py-12 hidden lg:block">
                    <p class="text-4xl">⊞</p>
                    <p class="mt-3 font-medium text-slate-900">Listo para vender</p>
                    <p class="mt-1 text-sm text-slate-500">
                        Escanea un codigo de barra o escribe el nombre del producto.
                    </p>
                </x-card>
            @endif
        </div>
    </div>

    {{-- ================= DERECHA: carrito ================= --}}
    <div class="lg:w-[26rem] lg:shrink-0 lg:flex lg:flex-col mt-4 lg:mt-0">

        {{-- Estado de la caja --}}
        @if ($this->shift === null)
            <div class="mb-3 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3">
                <p class="text-sm font-medium text-amber-900">La caja esta cerrada</p>
                <p class="text-xs text-amber-800 mt-0.5">Abrela para poder cobrar.</p>
                @can('cash.open')
                    <button type="button" wire:click="$set('showOpenShift', true)"
                            class="mt-2 text-sm font-medium text-amber-900 underline">
                        Abrir caja
                    </button>
                @endcan
            </div>
        @endif

        <div class="bg-white rounded-xl border border-slate-200 lg:flex-1 lg:flex lg:flex-col lg:min-h-0">

            {{-- Cliente y lista de precios --}}
            <div class="p-3 border-b border-slate-100 space-y-2">
                <select wire:model.live="customerId"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                    <option value="">Publico general</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="priceListId"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                    @foreach ($priceLists as $list)
                        <option value="{{ $list->id }}">Precio: {{ $list->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Lineas --}}
            <div class="lg:flex-1 lg:overflow-y-auto divide-y divide-slate-100">
                @forelse ($cart as $key => $line)
                    <div wire:key="cart-{{ $key }}" class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900 line-clamp-2">
                                    {{ $line['name'] }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $line['unit_label'] }}
                                    @if ($line['unit_factor'] > 1)
                                        (x{{ rtrim(rtrim(number_format($line['unit_factor'], 2), '0'), '.') }})
                                    @endif
                                    · {{ $currency?->symbol }}{{ number_format($line['unit_price'], 2) }}
                                </p>
                            </div>
                            <button type="button" wire:click="removeLine('{{ $key }}')"
                                    class="text-slate-300 hover:text-rose-600 text-lg leading-none shrink-0">
                                &times;
                            </button>
                        </div>

                        <div class="flex items-center justify-between gap-2 mt-2">
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="decrement('{{ $key }}')"
                                        class="w-8 h-8 grid place-items-center rounded-lg border border-slate-300
                                               text-slate-600 hover:bg-slate-50">&minus;</button>

                                <input type="number"
                                       step="{{ $line['allows_decimals'] ? '0.001' : '1' }}"
                                       min="0"
                                       inputmode="decimal"
                                       wire:model.live.debounce.600ms="cart.{{ $key }}.quantity"
                                       class="w-16 text-center rounded-lg border border-slate-300 py-1.5 text-sm tabular-nums
                                              focus:ring-2 focus:ring-indigo-500">

                                <button type="button" wire:click="increment('{{ $key }}')"
                                        class="w-8 h-8 grid place-items-center rounded-lg border border-slate-300
                                               text-slate-600 hover:bg-slate-50">+</button>
                            </div>

                            @php $promo = $this->linePromotions[$key] ?? null; @endphp

                            <div class="text-right">
                                <p @class([
                                    'font-semibold tabular-nums',
                                    'text-slate-400 line-through text-sm' => $promo,
                                    'text-slate-900' => ! $promo,
                                ])>
                                    {{ $currency?->symbol }}{{ number_format($line['total'], 2) }}
                                </p>
                                @if ($promo)
                                    <p class="font-semibold text-slate-900 tabular-nums">
                                        {{ $currency?->symbol }}{{ number_format(max(0, $line['total'] - $promo['discount']), 2) }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- La promocion se anuncia en la linea: el cliente
                             tiene que ver el ahorro, no solo un total menor. --}}
                        @if ($promo)
                            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                @foreach ($promo['labels'] as $label)
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-medium">
                                        {{ $label }}
                                    </span>
                                @endforeach
                                <span class="text-xs text-emerald-700 tabular-nums">
                                    ahorra {{ $currency?->symbol }}{{ number_format($promo['discount'], 2) }}
                                    @if ($promo['free'] > 0)
                                        · {{ rtrim(rtrim(number_format($promo['free'], 3), '0'), '.') }} gratis
                                    @endif
                                </span>
                            </div>
                        @endif

                        @can('sales.discount')
                            <div class="flex items-center gap-2 mt-2">
                                <label class="text-xs text-slate-500">Descuento</label>
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       wire:model.live.debounce.600ms="cart.{{ $key }}.discount"
                                       class="w-24 rounded-lg border border-slate-200 px-2 py-1 text-xs text-right tabular-nums
                                              focus:ring-2 focus:ring-indigo-500">
                            </div>
                        @endcan
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500">El carrito esta vacio</p>
                    </div>
                @endforelse
            </div>

            {{-- Totales --}}
            <div class="p-3 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                @if ($this->totals['discount'] > 0)
                    <div class="flex justify-between text-sm text-slate-600">
                        <span>Subtotal</span>
                        <span class="tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->totals['subtotal'], 2) }}
                        </span>
                    </div>
                    @if ($this->totals['promotion'] > 0)
                        <div class="flex justify-between text-sm text-emerald-700">
                            <span>Promociones</span>
                            <span class="tabular-nums">
                                −{{ $currency?->symbol }}{{ number_format($this->totals['promotion'], 2) }}
                            </span>
                        </div>
                    @endif

                    @if ($this->totals['discount'] - $this->totals['promotion'] > 0)
                        <div class="flex justify-between text-sm text-emerald-700">
                            <span>Descuento</span>
                            <span class="tabular-nums">
                                −{{ $currency?->symbol }}{{ number_format($this->totals['discount'] - $this->totals['promotion'], 2) }}
                            </span>
                        </div>
                    @endif
                @endif

                @if ($this->totals['tax'] > 0)
                    <div class="flex justify-between text-sm text-slate-600">
                        <span>Impuesto</span>
                        <span class="tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->totals['tax'], 2) }}
                        </span>
                    </div>
                @endif

                <div class="flex justify-between items-baseline mt-1.5 pt-1.5 border-t border-slate-200">
                    <span class="font-medium text-slate-700">Total</span>
                    <span class="text-2xl font-bold text-slate-900 tabular-nums">
                        {{ $currency?->symbol }}{{ number_format($this->totals['total'], 2) }}
                    </span>
                </div>

                <div class="flex gap-2 mt-3">
                    <button type="button" wire:click="hold" @disabled(empty($cart))
                            class="px-3 py-3 rounded-xl border border-slate-300 bg-white text-sm font-medium
                                   text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                        Esperar
                    </button>

                    <button type="button" wire:click="clearCart" @disabled(empty($cart))
                            class="px-3 py-3 rounded-xl border border-slate-300 bg-white text-sm font-medium
                                   text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                        Vaciar
                    </button>

                    <button type="button" wire:click="openPayment" @disabled(empty($cart))
                            class="flex-1 px-4 py-3 rounded-xl bg-indigo-600 text-white font-semibold
                                   hover:bg-indigo-700 disabled:opacity-40 transition">
                        Cobrar
                    </button>
                </div>
            </div>
        </div>

        @if ($this->lastSale)
            <a href="{{ route('sales.show', $this->lastSale) }}" wire:navigate
               class="mt-3 block text-center text-sm text-indigo-600 hover:text-indigo-700 font-medium">
                Ver ticket de {{ $this->lastSale->folio }}
            </a>
        @endif
    </div>

    {{-- ================= COBRO ================= --}}
    @if ($showPayment)
        <x-modal title="Cobrar" wire="showPayment">
            <div class="rounded-xl bg-slate-900 text-white px-4 py-4 text-center">
                <p class="text-sm text-slate-300">Total a cobrar</p>
                <p class="text-3xl font-bold tabular-nums mt-0.5">
                    {{ $currency?->symbol }}{{ number_format($this->totals['total'], 2) }}
                </p>
            </div>

            @error('payments')
                <p class="mt-3 text-sm text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
                    {{ $message }}
                </p>
            @enderror

            {{-- Atajos: cobrar todo con un solo metodo --}}
            <div class="grid grid-cols-2 gap-2 mt-4">
                @foreach ($this->paymentMethods as $method)
                    <button type="button" wire:click="payExact('{{ $method->id }}')"
                            @class([
                                'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                                'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    => count($payments) === 1 && array_key_first($payments) === $method->id,
                                'border-slate-300 text-slate-700 hover:bg-slate-50'
                                    => ! (count($payments) === 1 && array_key_first($payments) === $method->id),
                            ])>
                        {{ $method->name }}
                    </button>
                @endforeach
            </div>

            {{-- Montos por forma de pago --}}
            <div class="mt-4 space-y-2">
                @foreach ($this->paymentMethods as $method)
                    @if (array_key_exists($method->id, $payments))
                        <div class="flex items-center gap-2" wire:key="pay-{{ $method->id }}">
                            <span class="w-28 text-sm text-slate-700 shrink-0">{{ $method->name }}</span>
                            <div class="relative flex-1">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">
                                    {{ $currency?->symbol }}
                                </span>
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       wire:model.live.debounce.400ms="payments.{{ $method->id }}"
                                       class="w-full rounded-lg border border-slate-300 pl-7 pr-3 py-2.5
                                              text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                            </div>
                            @if (count($payments) > 1)
                                <button type="button" wire:click="removePaymentMethod('{{ $method->id }}')"
                                        class="text-slate-300 hover:text-rose-600 text-lg">&times;</button>
                            @endif
                        </div>
                    @else
                        <button type="button" wire:click="addPaymentMethod('{{ $method->id }}')"
                                wire:key="add-{{ $method->id }}"
                                class="text-xs text-indigo-600 hover:text-indigo-700 mr-3">
                            + {{ $method->name }}
                        </button>
                    @endif
                @endforeach
            </div>

            <div class="mt-4 pt-3 border-t border-slate-200 space-y-1">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-600">Recibido</span>
                    <span class="tabular-nums font-medium">
                        {{ $currency?->symbol }}{{ number_format($this->paidAmount, 2) }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="font-medium text-slate-700">Cambio</span>
                    <span class="text-xl font-bold tabular-nums
                                 {{ $this->changeAmount > 0 ? 'text-emerald-600' : 'text-slate-400' }}">
                        {{ $currency?->symbol }}{{ number_format($this->changeAmount, 2) }}
                    </span>
                </div>
            </div>

            <div class="flex gap-2 mt-5">
                <x-button type="button" variant="secondary" class="flex-1"
                          wire:click="$set('showPayment', false)">Cancelar</x-button>
                <x-button type="button" size="lg" class="flex-1" wire:click="checkout"
                          wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="checkout">Confirmar venta</span>
                    <span wire:loading wire:target="checkout">Registrando...</span>
                </x-button>
            </div>
        </x-modal>
    @endif

    {{-- ================= ABRIR CAJA ================= --}}
    @if ($showOpenShift)
        <x-modal title="Abrir caja" wire="showOpenShift">
            <form wire:submit="openShift" class="space-y-4">
                <p class="text-sm text-slate-600">
                    Cuenta el efectivo con el que empiezas. Sirve para cuadrar la caja al cerrar.
                </p>

                <x-input label="Fondo inicial" type="number" step="0.01" min="0"
                         wire:model="openingAmount" inputmode="decimal" placeholder="0.00"
                         :error="$errors->first('openingAmount')" autofocus />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showOpenShift', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Abrir caja</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- ================= VENTAS EN ESPERA ================= --}}
    @if ($showHeld)
        <x-modal title="Ventas en espera" wire="showHeld">
            @if ($this->heldSales->isEmpty())
                <p class="text-sm text-slate-500 text-center py-6">No hay ventas en espera.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($this->heldSales as $held)
                        <li class="flex items-center justify-between gap-3 py-3" wire:key="held-{{ $held->id }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $held->label }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $held->customer?->name ?? 'Publico general' }}
                                    · {{ $held->created_at->format('H:i') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-sm font-semibold tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($held->total, 2) }}
                                </span>
                                <button type="button" wire:click="resume('{{ $held->id }}')"
                                        class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                    Retomar
                                </button>
                                <button type="button" wire:click="discardHeld('{{ $held->id }}')"
                                        wire:confirm="Descartar {{ $held->label }}?"
                                        class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                    Borrar
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-modal>
    @endif
</div>
