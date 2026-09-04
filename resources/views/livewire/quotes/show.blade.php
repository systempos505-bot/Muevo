@php
    $badges = [
        'pending' => 'bg-amber-50 text-amber-700',
        'approved' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-slate-100 text-slate-600',
        'converted' => 'bg-indigo-50 text-indigo-700',
    ];
    $expired = $quote->isExpired();
@endphp

<div>
    <x-page-header :title="$quote->folio"
                   :subtitle="$quote->customerLabel() . ' · ' . $quote->created_at->format('d/m/Y')">
        <x-slot:actions>
            <a href="{{ route('quotes') }}" wire:navigate
               class="inline-flex items-center justify-center rounded-lg font-medium transition
                      px-3 py-1.5 text-sm bg-white text-slate-700 border border-slate-300 hover:bg-slate-50">
                Volver
            </a>
            @can('quotes.manage')
                @if ($quote->isEditable())
                    <a href="{{ route('quotes.edit', $quote) }}" wire:navigate
                       class="inline-flex items-center justify-center rounded-lg font-medium transition
                              px-3 py-1.5 text-sm bg-white text-slate-700 border border-slate-300 hover:bg-slate-50">
                        Editar
                    </a>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- ---------- Estado ---------- --}}
    <div class="flex flex-wrap items-center gap-3 mb-5">
        <span class="inline-block rounded-full px-3 py-1 text-sm font-medium
            {{ $expired ? 'bg-rose-50 text-rose-700' : ($badges[$quote->status] ?? 'bg-slate-100 text-slate-600') }}">
            {{ $quote->statusLabel() }}
        </span>
        <span class="text-sm text-slate-500">
            Vigente hasta {{ $quote->valid_until->format('d/m/Y') }}
        </span>
    </div>

    @if ($expired)
        <div class="mb-5 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            Esta cotizacion se paso de fecha, asi que el precio ya no esta comprometido.
            Para convertirla en venta hay que extender la vigencia primero — y esa es una
            decision del negocio, no algo que el sistema deba hacer solo.
        </div>
    @endif

    @if ($quote->isRejected() && $quote->reject_reason)
        <div class="mb-5 rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 text-sm text-slate-700">
            <strong>No se tomo:</strong> {{ $quote->reject_reason }}
        </div>
    @endif

    @if ($quote->isConverted() && $quote->sale)
        <div class="mb-5 rounded-lg bg-indigo-50 border border-indigo-200 px-4 py-3 text-sm text-indigo-800">
            Se convirtio en la venta
            <a href="{{ route('sales.show', $quote->sale) }}" wire:navigate
               class="font-medium underline">{{ $quote->sale->folio }}</a>
            el {{ $quote->converted_at?->format('d/m/Y') }}.
        </div>
    @endif

    <div class="grid lg:grid-cols-3 gap-5 items-start">
        {{-- ---------- Lineas ---------- --}}
        <div class="lg:col-span-2">
            <x-card flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="px-5 py-3 font-medium">Producto</th>
                                <th class="px-5 py-3 font-medium text-right">Cantidad</th>
                                <th class="px-5 py-3 font-medium text-right">Precio</th>
                                <th class="px-5 py-3 font-medium text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($quote->items as $item)
                                <tr wire:key="i-{{ $item->id }}">
                                    <td class="px-5 py-3">
                                        <span class="block text-slate-900">{{ $item->description }}</span>
                                        <span class="block text-xs text-slate-400">
                                            {{ $item->sku }}
                                            @if ($item->unit_label) · {{ $item->unit_label }} @endif
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700">
                                        {{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700">
                                        {{ $currency?->symbol }}{{ number_format($item->unit_price, 2) }}
                                        @if ($item->discount > 0)
                                            <span class="block text-xs text-emerald-700">
                                                −{{ $currency?->symbol }}{{ number_format($item->discount, 2) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900">
                                        {{ $currency?->symbol }}{{ number_format($item->total, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            @if ($quote->notes)
                <x-card title="Notas" class="mt-5">
                    <p class="text-sm text-slate-700 whitespace-pre-line">{{ $quote->notes }}</p>
                </x-card>
            @endif
        </div>

        {{-- ---------- Resumen y acciones ---------- --}}
        <div class="space-y-5">
            <x-card title="Total">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($quote->subtotal, 2) }}
                        </dd>
                    </div>
                    @if ($quote->discount > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Descuento</dt>
                            <dd class="tabular-nums text-emerald-700">
                                −{{ $currency?->symbol }}{{ number_format($quote->discount, 2) }}
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Impuesto</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($quote->tax, 2) }}
                        </dd>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-slate-100 text-base font-semibold">
                        <dt class="text-slate-900">Total</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($quote->total, 2) }}
                        </dd>
                    </div>
                </dl>

                @if ($quote->customer_phone)
                    <p class="mt-4 pt-3 border-t border-slate-100 text-sm text-slate-500">
                        Telefono: <span class="text-slate-800">{{ $quote->customer_phone }}</span>
                    </p>
                @endif
            </x-card>

            @can('quotes.manage')
                @if (! $quote->isConverted())
                    <x-card title="Acciones">
                        <div class="space-y-2">
                            @if ($quote->isPending())
                                <x-button class="w-full" wire:click="approve">
                                    El cliente la acepto
                                </x-button>
                            @endif

                            @if ($quote->isConvertible())
                                @can('sales.create')
                                    <x-button class="w-full" variant="secondary"
                                              wire:click="$set('showConvert', true)">
                                        Convertir en venta
                                    </x-button>
                                @endcan
                            @endif

                            @if ($expired)
                                <x-button class="w-full" variant="secondary"
                                          wire:click="$set('showExtend', true)">
                                    Extender vigencia
                                </x-button>
                            @endif

                            @if ($quote->isRejected())
                                <x-button class="w-full" variant="secondary" wire:click="reopen">
                                    Reabrir
                                </x-button>
                            @else
                                <x-button class="w-full" variant="ghost"
                                          wire:click="$set('showReject', true)">
                                    No la tomo
                                </x-button>
                            @endif
                        </div>
                    </x-card>
                @endif
            @endcan
        </div>
    </div>

    {{-- ---------- Rechazar ---------- --}}
    @if ($showReject)
        <x-modal title="No se tomo la cotizacion" wire="showReject">
            <x-input label="Motivo" wire:model="rejectReason"
                     placeholder="Encontro mas barato, ya no lo necesita..."
                     :error="$errors->first('rejectReason')" />

            <p class="mt-3 text-xs text-slate-500">
                Se guarda para saber despues por que no se cerro. La cotizacion no se
                borra: se puede reabrir si el cliente cambia de opinion.
            </p>

            <div class="flex gap-2 mt-5">
                <x-button variant="secondary" class="flex-1" wire:click="$set('showReject', false)">
                    Cancelar
                </x-button>
                <x-button variant="danger" class="flex-1" wire:click="reject">Confirmar</x-button>
            </div>
        </x-modal>
    @endif

    {{-- ---------- Extender vigencia ---------- --}}
    @if ($showExtend)
        <x-modal title="Extender la vigencia" wire="showExtend">
            <x-input type="date" label="Vigente hasta" wire:model="newValidUntil"
                     :error="$errors->first('newValidUntil')" />

            <p class="mt-3 text-xs text-slate-500">
                Los precios no se recalculan: siguen siendo los que se cotizaron. Si el
                negocio quiere cobrar los de hoy, hay que editar las lineas.
            </p>

            <div class="flex gap-2 mt-5">
                <x-button variant="secondary" class="flex-1" wire:click="$set('showExtend', false)">
                    Cancelar
                </x-button>
                <x-button class="flex-1" wire:click="extend">Guardar</x-button>
            </div>
        </x-modal>
    @endif

    {{-- ---------- Convertir en venta ---------- --}}
    @if ($showConvert)
        <x-modal title="Convertir en venta" wire="showConvert">
            @if ($this->shift === null)
                <p class="text-sm text-slate-700">
                    Necesitas un turno de caja abierto: una venta tiene que quedar dentro
                    de un turno para poder cuadrar el efectivo al final del dia.
                </p>
                <a href="{{ route('cash') }}" wire:navigate
                   class="inline-flex items-center justify-center w-full mt-5 rounded-lg font-medium
                          px-4 py-2.5 text-sm bg-indigo-600 text-white hover:bg-indigo-700">
                    Ir a abrir la caja
                </a>
            @else
                <p class="text-sm text-slate-600">
                    Se va a generar una venta por
                    <strong class="text-slate-900">
                        {{ $currency?->symbol }}{{ number_format($quote->total, 2) }}
                    </strong>,
                    con los precios que se cotizaron. El inventario se descuenta en ese
                    momento, no antes.
                </p>

                <div class="space-y-1.5 mt-4">
                    <label class="block text-sm font-medium text-slate-700">Forma de pago</label>
                    <select wire:model="paymentMethodId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach ($this->paymentMethods as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                    @error('paymentMethodId')
                        <p class="text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-2 mt-5">
                    <x-button variant="secondary" class="flex-1" wire:click="$set('showConvert', false)">
                        Cancelar
                    </x-button>
                    <x-button class="flex-1" wire:click="convert" wire:loading.attr="disabled">
                        Generar venta
                    </x-button>
                </div>
            @endif
        </x-modal>
    @endif
</div>
