@php $symbol = $currency?->symbol ?? '$'; @endphp

<div>
    <x-page-header title="Devolucion" :subtitle="$note->folio">
        <x-slot:actions>
            <a href="{{ route('returns') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
            @if ($note->sale)
                <a href="{{ route('sales.show', $note->sale) }}" wire:navigate>
                    <x-button variant="secondary" size="sm">Ver la venta</x-button>
                </a>
            @endif
            <x-button variant="secondary" size="sm" onclick="window.print()">Imprimir</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="max-w-sm mx-auto bg-white rounded-xl border border-slate-200 p-6 print:border-0 print:shadow-none">

        <div class="text-center pb-4 border-b border-dashed border-slate-300">
            <p class="font-bold text-slate-900">{{ $tenant->trade_name ?? $tenant->name }}</p>
            <p class="text-xs font-medium text-slate-600 mt-1">NOTA DE CREDITO</p>
        </div>

        <div class="py-3 text-xs text-slate-600 space-y-0.5 border-b border-dashed border-slate-300">
            <div class="flex justify-between">
                <span>Folio</span>
                <span class="font-medium text-slate-900">{{ $note->folio }}</span>
            </div>
            <div class="flex justify-between">
                <span>Fecha</span>
                <span>{{ $note->created_at->format('d/m/Y H:i') }}</span>
            </div>
            @if ($note->sale)
                <div class="flex justify-between">
                    <span>Venta</span>
                    <span>{{ $note->sale->folio }}</span>
                </div>
            @endif
            <div class="flex justify-between">
                <span>Atendio</span>
                <span>{{ $note->user?->name }}</span>
            </div>
            <div class="flex justify-between">
                <span>Cliente</span>
                <span>{{ $note->customer?->name ?? 'Publico general' }}</span>
            </div>
        </div>

        <div class="py-3 space-y-2 border-b border-dashed border-slate-300">
            @foreach ($note->items as $item)
                <div class="text-sm">
                    <p class="text-slate-900">{{ $item->description }}</p>
                    <div class="flex justify-between text-xs text-slate-600 mt-0.5">
                        <span>
                            {{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}
                            {{ $item->unit_label }}
                            × {{ $symbol }}{{ number_format($item->unit_price, 2) }}
                        </span>
                        <span class="tabular-nums font-medium text-slate-900">
                            {{ $symbol }}{{ number_format($item->total, 2) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="py-3 text-sm space-y-1 border-b border-dashed border-slate-300">
            @if ($note->tax > 0)
                <div class="flex justify-between text-slate-600">
                    <span>Subtotal</span>
                    <span class="tabular-nums">{{ $symbol }}{{ number_format($note->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between text-slate-600">
                    <span>Impuesto</span>
                    <span class="tabular-nums">{{ $symbol }}{{ number_format($note->tax, 2) }}</span>
                </div>
            @endif

            <div class="flex justify-between font-bold text-base text-slate-900 pt-1">
                <span>Total devuelto</span>
                <span class="tabular-nums">{{ $symbol }}{{ number_format($note->total, 2) }}</span>
            </div>
        </div>

        <div class="py-3 text-xs text-slate-600 space-y-0.5">
            <div class="flex justify-between">
                <span>{{ $note->typeLabel() }}</span>
                <span>{{ $note->paymentMethod?->name ?? ($note->isRefund() ? 'Efectivo' : 'A cuenta del cliente') }}</span>
            </div>
            <div class="flex justify-between">
                <span>Mercancia</span>
                <span>{{ $note->restock ? 'Volvio al inventario' : 'No volvio al inventario' }}</span>
            </div>
        </div>

        <p class="text-xs text-slate-500 border-t border-dashed border-slate-300 pt-3">
            {{ $note->reason }}
        </p>

        @if ($note->notes)
            <p class="text-xs text-slate-500 mt-1">{{ $note->notes }}</p>
        @endif
    </div>
</div>
