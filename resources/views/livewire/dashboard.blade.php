<div>
    <x-slot:header>Hola, {{ str(auth()->user()->name)->before(' ') }}</x-slot:header>
    <x-slot:subheader>{{ auth()->user()->tenant->name }}</x-slot:subheader>

    {{-- Indicadores. En celular van de dos en dos. --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <x-card>
            <p class="text-sm text-slate-500">Productos activos</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($this->stats['products']) }}</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Stock bajo</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-amber-600' => $this->stats['lowStock'] > 0,
            ])>{{ number_format($this->stats['lowStock']) }}</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Agotados</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-rose-600' => $this->stats['outOfStock'] > 0,
            ])>{{ number_format($this->stats['outOfStock']) }}</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Por vencer</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-rose-600' => $this->stats['expiring'] > 0,
            ])>{{ number_format($this->stats['expiring']) }}</p>
        </x-card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <x-card title="Stock bajo" description="Productos que conviene reponer" flush>
            @if ($this->lowStockItems->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">
                    Nada por reponer. Todo el inventario esta sobre su minimo.
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($this->lowStockItems as $item)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('products.edit', $item->product) }}" wire:navigate
                                   class="text-sm font-medium text-slate-900 hover:text-indigo-600 truncate block">
                                    {{ $item->product->name }}
                                </a>
                                <p class="text-xs text-slate-500">{{ $item->branch->name }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p @class([
                                    'text-sm font-semibold tabular-nums',
                                    'text-rose-600' => $item->quantity <= 0,
                                    'text-amber-600' => $item->quantity > 0,
                                ])>
                                    {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}
                                    <span class="text-xs font-normal text-slate-400">
                                        {{ $item->product->baseUnit?->code }}
                                    </span>
                                </p>
                                <p class="text-xs text-slate-400">
                                    min {{ rtrim(rtrim(number_format($item->effectiveMinStock(), 2), '0'), '.') }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Proximos a vencer" description="Los siguientes 60 dias" flush>
            @if ($this->expiringLots->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">
                    Ningun lote vence en los proximos 60 dias.
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($this->expiringLots as $lot)
                        @php $days = $lot->daysLeft(); @endphp
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('products.edit', $lot->product) }}" wire:navigate
                                   class="text-sm font-medium text-slate-900 hover:text-indigo-600 truncate block">
                                    {{ $lot->product->name }}
                                </a>
                                <p class="text-xs text-slate-500">
                                    Lote {{ $lot->lot_number }} · {{ $lot->branch->name }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p @class([
                                    'text-sm font-semibold',
                                    'text-rose-600' => $days !== null && $days <= 0,
                                    'text-amber-600' => $days !== null && $days > 0 && $days <= 30,
                                    'text-slate-600' => $days !== null && $days > 30,
                                ])>
                                    @if ($days === null)
                                        —
                                    @elseif ($days < 0)
                                        Vencido
                                    @elseif ($days === 0)
                                        Vence hoy
                                    @else
                                        {{ $days }} dias
                                    @endif
                                </p>
                                <p class="text-xs text-slate-400">
                                    {{ $lot->expiry_date?->format('d/m/Y') }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</div>
