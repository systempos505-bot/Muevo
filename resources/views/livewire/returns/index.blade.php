@php $symbol = $currency?->symbol ?? '$'; @endphp

<div>
    <x-page-header title="Devoluciones"
                   :subtitle="$this->summary['notes'] . ' devolucion(es) en el periodo'" />

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Total devuelto</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600">
                {{ $symbol }}{{ number_format($this->summary['total'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">En dinero</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {{ $symbol }}{{ number_format($this->summary['refunded'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">En saldo a favor</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {{ $symbol }}{{ number_format($this->summary['total'] - $this->summary['refunded'], 2) }}
            </p>
        </x-card>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por folio, venta, cliente o motivo"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="type"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Dinero y saldo</option>
            <option value="refund">Solo dinero</option>
            <option value="credit">Solo saldo a favor</option>
        </select>

        <input type="date" wire:model.live="from" aria-label="Desde"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
        <input type="date" wire:model.live="to" aria-label="Hasta"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
    </div>

    @if ($notes->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">↩</p>
            <p class="mt-3 font-medium text-slate-900">Sin devoluciones en este periodo</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                Una devolucion se registra desde el ticket de la venta, para que quede
                ligada a lo que se cobro.
            </p>
            <div class="mt-5 flex justify-center gap-2">
                <x-button variant="secondary" wire:click="clearFilters">Quitar filtros</x-button>
                <a href="{{ route('sales') }}" wire:navigate>
                    <x-button>Ir a ventas</x-button>
                </a>
            </div>
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Venta</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Cliente</th>
                            <th class="px-5 py-3 font-medium">Motivo</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($notes as $note)
                            <tr class="hover:bg-slate-50 cursor-pointer"
                                wire:key="note-{{ $note->id }}"
                                onclick="window.location='{{ route('returns.show', $note) }}'">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('returns.show', $note) }}" wire:navigate
                                       class="text-indigo-600 hover:underline font-medium">
                                        {{ $note->folio }}
                                    </a>
                                    <p class="text-xs text-slate-400">
                                        {{ $note->created_at->format('d/m/Y H:i') }}
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $note->sale?->folio ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden lg:table-cell">
                                    {{ $note->customer?->name ?? 'Publico general' }}
                                </td>
                                <td class="px-5 py-3">
                                    <p class="text-slate-700">{{ $note->reason }}</p>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span @class([
                                            'px-1.5 py-0.5 rounded text-xs',
                                            'bg-slate-100 text-slate-600' => $note->isRefund(),
                                            'bg-indigo-100 text-indigo-700' => ! $note->isRefund(),
                                        ])>{{ $note->typeLabel() }}</span>

                                        @unless ($note->restock)
                                            <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-xs">
                                                no volvio al estante
                                            </span>
                                        @endunless
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-rose-600">
                                    −{{ $symbol }}{{ number_format($note->total, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $notes->links() }}</div>
    @endif
</div>
