<div>
    <x-page-header title="Ticket" :subtitle="$sale->folio">
        <x-slot:actions>
            <a href="{{ route('sales') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
            <x-button variant="secondary" size="sm" onclick="window.print()">Imprimir</x-button>
            @if (! $sale->isCancelled())
                @can('sales.return')
                    @if ($sale->hasReturnableItems())
                        <x-button variant="secondary" size="sm" wire:click="openReturn">Devolucion</x-button>
                    @endif
                @endcan
                @can('sales.void')
                    <x-button variant="danger" size="sm" wire:click="$set('showCancel', true)">Anular</x-button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($sale->isCancelled())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3">
            <p class="text-sm font-medium text-rose-800">
                Venta anulada el {{ $sale->cancelled_at?->format('d/m/Y H:i') }}
            </p>
            <p class="text-sm text-rose-700 mt-0.5">{{ $sale->cancel_reason }}</p>
        </div>
    @endif

    @if ($sale->creditNotes->isNotEmpty())
        <div class="max-w-sm mx-auto mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3">
            <p class="text-sm font-medium text-amber-800">
                Esta venta tiene devoluciones por
                {{ $currency?->symbol }}{{ number_format($sale->returnedTotal(), 2) }}
            </p>
            <div class="mt-1 space-y-0.5">
                @foreach ($sale->creditNotes as $note)
                    <a href="{{ route('returns.show', $note) }}" wire:navigate
                       class="block text-xs text-amber-700 hover:underline">
                        {{ $note->folio }} · {{ $note->created_at->format('d/m/Y') }} ·
                        {{ $currency?->symbol }}{{ number_format($note->total, 2) }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- El ticket se dibuja angosto, como saldria de una impresora
         termica de 80 mm, para que lo impreso se parezca a lo que se ve. --}}
    <div class="max-w-sm mx-auto bg-white rounded-xl border border-slate-200 p-6 print:border-0 print:shadow-none">

        <div class="text-center pb-4 border-b border-dashed border-slate-300">
            <p class="font-bold text-slate-900">{{ $tenant->trade_name ?? $tenant->name }}</p>
            @if ($tenant->address)
                <p class="text-xs text-slate-500 mt-0.5">{{ $tenant->address }}</p>
            @endif
            @if ($tenant->phone)
                <p class="text-xs text-slate-500">Tel. {{ $tenant->phone }}</p>
            @endif
        </div>

        <div class="py-3 text-xs text-slate-600 space-y-0.5 border-b border-dashed border-slate-300">
            <div class="flex justify-between">
                <span>Folio</span>
                <span class="font-medium text-slate-900">{{ $sale->folio }}</span>
            </div>
            <div class="flex justify-between">
                <span>Fecha</span>
                <span>{{ $sale->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <div class="flex justify-between">
                <span>Atendio</span>
                <span>{{ $sale->user?->name }}</span>
            </div>
            <div class="flex justify-between">
                <span>Cliente</span>
                <span>{{ $sale->customer?->name ?? 'Publico general' }}</span>
            </div>
        </div>

        <div class="py-3 space-y-2 border-b border-dashed border-slate-300">
            @foreach ($sale->items as $item)
                <div class="text-sm">
                    <p class="text-slate-900">{{ $item->description }}</p>
                    <div class="flex justify-between text-xs text-slate-600 mt-0.5">
                        <span>
                            {{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}
                            {{ $item->unit_label }}
                            × {{ $currency?->symbol }}{{ number_format($item->unit_price, 2) }}
                        </span>
                        <span class="tabular-nums font-medium text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($item->total, 2) }}
                        </span>
                    </div>
                    {{-- La promocion se nombra en el ticket: el cliente
                         tiene que poder ver por que pago menos. --}}
                    @foreach ($item->promotions as $promotion)
                        <p class="text-xs text-emerald-700">
                            {{ $promotion->label }}
                            −{{ $currency?->symbol }}{{ number_format($promotion->discount, 2) }}
                            @if ($promotion->free_quantity > 0)
                                ({{ rtrim(rtrim(number_format($promotion->free_quantity, 3), '0'), '.') }} gratis)
                            @endif
                        </p>
                    @endforeach

                    @php $manual = $item->discount - $item->promotions->sum('discount'); @endphp

                    @if ($manual > 0)
                        <p class="text-xs text-emerald-700">
                            Descuento −{{ $currency?->symbol }}{{ number_format($manual, 2) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="py-3 text-sm space-y-1 border-b border-dashed border-slate-300">
            @if ($sale->discount > 0)
                <div class="flex justify-between text-slate-600">
                    <span>Subtotal</span>
                    <span class="tabular-nums">{{ $currency?->symbol }}{{ number_format($sale->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between text-emerald-700">
                    <span>Descuento</span>
                    <span class="tabular-nums">−{{ $currency?->symbol }}{{ number_format($sale->discount, 2) }}</span>
                </div>
            @endif

            @if ($sale->tax > 0)
                <div class="flex justify-between text-slate-600">
                    <span>Impuesto</span>
                    <span class="tabular-nums">{{ $currency?->symbol }}{{ number_format($sale->tax, 2) }}</span>
                </div>
            @endif

            <div class="flex justify-between font-bold text-base text-slate-900 pt-1">
                <span>Total</span>
                <span class="tabular-nums">{{ $currency?->symbol }}{{ number_format($sale->total, 2) }}</span>
            </div>
        </div>

        <div class="py-3 text-xs text-slate-600 space-y-0.5">
            @foreach ($sale->payments as $payment)
                <div class="flex justify-between">
                    <span>{{ $payment->method_label }}</span>
                    <span class="tabular-nums">
                        {{ $currency?->symbol }}{{ number_format($payment->amount, 2) }}
                    </span>
                </div>
            @endforeach

            @if ($sale->change > 0)
                <div class="flex justify-between font-medium text-slate-900 pt-1">
                    <span>Cambio</span>
                    <span class="tabular-nums">{{ $currency?->symbol }}{{ number_format($sale->change, 2) }}</span>
                </div>
            @endif
        </div>

        @if ($sale->notes)
            <p class="text-xs text-slate-500 border-t border-dashed border-slate-300 pt-3">
                {{ $sale->notes }}
            </p>
        @endif

        <p class="text-center text-xs text-slate-400 mt-4">Gracias por su compra</p>
    </div>

    @if ($showReturn)
        <x-modal title="Registrar devolucion" wire="showReturn">
            <form wire:submit="saveReturn" class="space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-600">Indica cuanto vuelve de cada linea.</p>
                    <x-button type="button" variant="ghost" size="sm" wire:click="returnAll">
                        Todo
                    </x-button>
                </div>

                <div class="space-y-2">
                    @foreach ($sale->items as $item)
                        @php $pending = $this->returnable[$item->id] ?? 0; @endphp
                        <div wire:key="ret-{{ $item->id }}"
                             class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900 truncate">{{ $item->description }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($item->effectiveUnitPrice(), 2) }}
                                    por {{ $item->unit_label ?? 'unidad' }} ·
                                    quedan {{ rtrim(rtrim(number_format($pending, 3), '0'), '.') }}
                                </p>
                            </div>

                            <input type="number" step="0.001" min="0" max="{{ $pending }}"
                                   inputmode="decimal"
                                   @disabled($pending <= 0)
                                   wire:model="returnLines.{{ $item->id }}"
                                   class="w-20 shrink-0 text-center rounded-lg border border-slate-300 py-1.5 text-sm
                                          tabular-nums focus:ring-2 focus:ring-indigo-500 disabled:bg-slate-50">
                        </div>
                    @endforeach
                </div>

                @error('returnLines')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Como se le regresa</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label @class([
                            'px-3 py-2.5 rounded-lg border text-sm text-center cursor-pointer transition',
                            'bg-indigo-600 border-indigo-600 text-white' => $returnType === 'refund',
                            'border-slate-300 text-slate-600 hover:bg-slate-50' => $returnType !== 'refund',
                        ])>
                            <input type="radio" value="refund" wire:model.live="returnType" class="sr-only">
                            Dinero
                        </label>
                        <label @class([
                            'px-3 py-2.5 rounded-lg border text-sm text-center cursor-pointer transition',
                            'bg-indigo-600 border-indigo-600 text-white' => $returnType === 'credit',
                            'border-slate-300 text-slate-600 hover:bg-slate-50' => $returnType !== 'credit',
                        ])>
                            <input type="radio" value="credit" wire:model.live="returnType" class="sr-only">
                            Saldo a favor
                        </label>
                    </div>

                    @if ($returnType === 'credit' && ! $sale->customer_id)
                        <p class="text-xs text-rose-600">
                            Esta venta no tiene cliente, asi que no hay a quien dejarle el saldo.
                        </p>
                    @endif
                </div>

                @if ($returnType === 'refund')
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">De donde sale el dinero</label>
                        <select wire:model="returnMethodId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                                       focus:ring-2 focus:ring-indigo-500">
                            <option value="">Por donde entro</option>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Lo dañado no vuelve al estante: seria inventar
                     existencia vendible que no existe. --}}
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="returnRestock"
                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    La mercancia vuelve al inventario
                </label>

                <x-input label="Motivo" wire:model="returnReason"
                         placeholder="No le quedo la talla"
                         :error="$errors->first('returnReason')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showReturn', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Registrar devolucion</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showCancel)
        <x-modal title="Anular venta" wire="showCancel">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-slate-600">
                    La mercancia vuelve al inventario, el dinero sale de la cuenta
                    por la que entro y queda registrada la razon. La venta no se
                    borra: se marca como anulada.
                </p>

                <x-input label="Motivo" wire:model="cancelReason"
                         placeholder="El cliente devolvio el producto"
                         :error="$errors->first('cancelReason')" autofocus />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showCancel', false)">Cancelar</x-button>
                    <x-button type="submit" variant="danger" class="flex-1">Anular venta</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
