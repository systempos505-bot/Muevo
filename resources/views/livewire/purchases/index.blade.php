<div>
    <x-page-header title="Compras" :subtitle="$this->summary['purchases'] . ' compra(s)'">
        <x-slot:actions>
            @can('purchases.create')
                <a href="{{ route('purchases.create') }}" wire:navigate>
                    <x-button size="sm">+ Nueva compra</x-button>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Comprado</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ $currency?->symbol }}{{ number_format($this->summary['total'], 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Compras</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $this->summary['purchases'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Por pagar</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-amber-600' => $this->summary['pending'] > 0,
            ])>{{ $currency?->symbol }}{{ number_format($this->summary['pending'], 2) }}</p>
        </x-card>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por folio o factura"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="supplierId"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los proveedores</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="filter"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="all">Recibidas</option>
            <option value="pending">Por pagar</option>
            <option value="overdue">Vencidas</option>
            <option value="cancelled">Anuladas</option>
        </select>
    </div>

    @if ($purchases->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">▽</p>
            <p class="mt-3 font-medium text-slate-900">Sin compras registradas</p>
            <p class="mt-1 text-sm text-slate-500">
                Registra la mercancia que llega para que entre con su costo y su documento.
            </p>
            @can('purchases.create')
                <div class="mt-5">
                    <a href="{{ route('purchases.create') }}" wire:navigate>
                        <x-button>Registrar una compra</x-button>
                    </a>
                </div>
            @endcan
        </x-card>
    @else
        <div class="space-y-2 lg:hidden">
            @foreach ($purchases as $purchase)
                <a href="{{ route('purchases.show', ['purchaseId' => $purchase->id]) }}" wire:navigate
                   class="block bg-white rounded-xl border border-slate-200 p-4 active:bg-slate-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ $purchase->folio }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $purchase->created_at->format('d/m/Y') }}
                                · {{ $purchase->supplier?->name ?? 'Sin proveedor' }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-semibold tabular-nums">
                                {{ $currency?->symbol }}{{ number_format($purchase->total, 2) }}
                            </p>
                            @if ($purchase->isCancelled())
                                <span class="text-xs text-rose-600">Anulada</span>
                            @elseif (! $purchase->isPaid())
                                <span @class([
                                    'text-xs',
                                    'text-rose-600' => $purchase->isOverdue(),
                                    'text-amber-600' => ! $purchase->isOverdue(),
                                ])>Debe {{ $currency?->symbol }}{{ number_format($purchase->balance(), 2) }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <x-card flush class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Proveedor</th>
                            <th class="px-5 py-3 font-medium">Factura</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                            <th class="px-5 py-3 font-medium text-right">Saldo</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($purchases as $purchase)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('purchases.show', ['purchaseId' => $purchase->id]) }}"
                                       wire:navigate class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $purchase->folio }}
                                    </a>
                                    @if ($purchase->isCancelled())
                                        <span class="ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-xs">
                                            anulada
                                        </span>
                                    @elseif ($purchase->isOverdue())
                                        <span class="ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-xs">
                                            vencida
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $purchase->created_at->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $purchase->supplier?->name ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $purchase->invoice_number ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($purchase->total, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if ($purchase->isPaid())
                                        <span class="text-emerald-700">Pagada</span>
                                    @else
                                        <span @class([
                                            'font-medium',
                                            'text-rose-600' => $purchase->isOverdue(),
                                            'text-amber-600' => ! $purchase->isOverdue(),
                                        ])>{{ $currency?->symbol }}{{ number_format($purchase->balance(), 2) }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('purchases.show', ['purchaseId' => $purchase->id]) }}"
                                       wire:navigate class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                        Ver
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $purchases->links() }}</div>
    @endif
</div>
