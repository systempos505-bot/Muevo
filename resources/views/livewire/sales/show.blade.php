<div>
    <x-page-header title="Ticket" :subtitle="$sale->folio">
        <x-slot:actions>
            <a href="{{ route('sales') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
            <x-button variant="secondary" size="sm" onclick="window.print()">Imprimir</x-button>
            @if (! $sale->isCancelled())
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
                    @if ($item->discount > 0)
                        <p class="text-xs text-emerald-700">
                            Descuento −{{ $currency?->symbol }}{{ number_format($item->discount, 2) }}
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

    @if ($showCancel)
        <x-modal title="Anular venta" wire="showCancel">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-slate-600">
                    La mercancia vuelve al inventario y queda registrada la razon.
                    La venta no se borra: se marca como anulada.
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
