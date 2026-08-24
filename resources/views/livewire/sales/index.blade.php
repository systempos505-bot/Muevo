<div>
    <x-page-header title="Ventas" :subtitle="$this->summary['sales'] . ' venta(s) en el periodo'" />

    {{-- Resumen del periodo filtrado, no solo de la pagina visible --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Vendido</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ $currency?->symbol }}{{ number_format($this->summary['total'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Ventas</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $this->summary['sales'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Ticket promedio</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ $currency?->symbol }}{{ number_format($this->summary['average'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Utilidad</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-emerald-700' => $this->summary['profit'] > 0,
                'text-rose-600' => $this->summary['profit'] < 0,
            ])>{{ $currency?->symbol }}{{ number_format($this->summary['profit'], 2) }}</p>
        </x-card>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por folio o cliente"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <input type="date" wire:model.live="from"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
        <input type="date" wire:model.live="to"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="userId"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los cajeros</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="completed">Completadas</option>
            <option value="cancelled">Anuladas</option>
            <option value="all">Todas</option>
        </select>
    </div>

    @if ($sales->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">◔</p>
            <p class="mt-3 font-medium text-slate-900">Sin ventas en este periodo</p>
            <p class="mt-1 text-sm text-slate-500">Ajusta los filtros o registra tu primera venta.</p>
            <div class="mt-5 flex justify-center gap-2">
                <x-button variant="secondary" wire:click="clearFilters">Quitar filtros</x-button>
                @can('sales.create')
                    <a href="{{ route('pos') }}" wire:navigate><x-button>Ir a vender</x-button></a>
                @endcan
            </div>
        </x-card>
    @else
        {{-- Tarjetas: celular --}}
        <div class="space-y-2 lg:hidden">
            @foreach ($sales as $sale)
                <a href="{{ route('sales.show', $sale) }}" wire:navigate
                   class="block bg-white rounded-xl border border-slate-200 p-4 active:bg-slate-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ $sale->folio }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $sale->created_at->format('d/m/Y H:i') }}
                                · {{ $sale->customer?->name ?? 'Publico general' }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-semibold tabular-nums">
                                {{ $currency?->symbol }}{{ number_format($sale->total, 2) }}
                            </p>
                            @if ($sale->isCancelled())
                                <span class="text-xs text-rose-600">Anulada</span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Tabla: escritorio --}}
        <x-card flush class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Cliente</th>
                            <th class="px-5 py-3 font-medium">Cajero</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                            <th class="px-5 py-3 font-medium text-right">Utilidad</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($sales as $sale)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('sales.show', $sale) }}" wire:navigate
                                       class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $sale->folio }}
                                    </a>
                                    @if ($sale->isCancelled())
                                        <span class="ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-xs">
                                            anulada
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $sale->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $sale->customer?->name ?? 'Publico general' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $sale->user?->name }}</td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($sale->total, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'text-emerald-700' => $sale->profit() > 0,
                                        'text-rose-600' => $sale->profit() < 0,
                                        'text-slate-500' => $sale->profit() == 0,
                                    ])>{{ $currency?->symbol }}{{ number_format($sale->profit(), 2) }}</span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('sales.show', $sale) }}" wire:navigate
                                       class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                        Ticket
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $sales->links() }}</div>
    @endif
</div>
