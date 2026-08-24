@php
    // El signo va antes del simbolo: "L-162.00" se lee como un simbolo
    // raro, "-L162.00" se lee como lo que es, una perdida.
    $money = function ($amount) use ($currency) {
        $amount = (float) $amount;
        $symbol = $currency?->symbol ?? '$';

        return ($amount < 0 ? '-' : '').$symbol.number_format(abs($amount), 2);
    };
    $presets = [
        'hoy' => 'Hoy',
        'ayer' => 'Ayer',
        'semana' => '7 dias',
        'mes' => 'Este mes',
        'mes_pasado' => 'Mes pasado',
        'ano' => 'Este ano',
    ];
@endphp

<div>
    <x-page-header title="Reportes"
                   :subtitle="\Illuminate\Support\Carbon::parse($periodFrom)->format('d/m/Y') . ' al ' . \Illuminate\Support\Carbon::parse($periodTo)->format('d/m/Y')">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" wire:click="export">
                Exportar CSV
            </x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Periodo: se queda arriba y no cambia al saltar de pestana, para
         poder comparar el mismo rango desde varios angulos. --}}
    <div class="flex flex-col gap-3 mb-4">
        <div class="flex gap-1.5 overflow-x-auto pb-1">
            @foreach ($presets as $key => $label)
                <button type="button" wire:click="preset('{{ $key }}')"
                        class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-sm
                               text-slate-700 whitespace-nowrap hover:bg-slate-50">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
            <input type="date" wire:model.live="from" aria-label="Desde"
                   class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <input type="date" wire:model.live="to" aria-label="Hasta"
                   class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">

            @if ($branches->count() > 1)
                <select wire:model.live="branchId"
                        class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Todas las sucursales</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    <div class="flex gap-1 mb-4 overflow-x-auto border-b border-slate-200">
        @foreach (\App\Livewire\Reports\Index::TABS as $key => $label)
            <button type="button" wire:click="selectTab('{{ $key }}')"
                @class([
                    'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition',
                    'border-indigo-600 text-indigo-600' => $tab === $key,
                    'border-transparent text-slate-500 hover:text-slate-800' => $tab !== $key,
                ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ======================= Resumen ======================= --}}
    @if ($tab === 'resumen')
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <x-card>
                <p class="text-sm text-slate-500">Vendido</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                    {{ $money($this->sales['total']) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-400">
                    {{ $this->sales['sales'] }} venta(s)
                    @if ($this->returns['total'] > 0)
                        · {{ $money($this->returns['total']) }} devuelto
                    @endif
                </p>
            </x-card>

            <x-card>
                <p class="text-sm text-slate-500">Utilidad bruta</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">
                    {{ $money($this->profit['gross_profit']) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-400">
                    costo {{ $money($this->profit['cost']) }}
                </p>
            </x-card>

            <x-card>
                <p class="text-sm text-slate-500">Gastos</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600">
                    {{ $money($this->profit['expenses']) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-400">
                    compras {{ $money($this->purchases['total']) }}
                </p>
            </x-card>

            {{-- La cifra que de verdad importa: lo que queda al final. --}}
            <x-card @class(['ring-2', 'ring-emerald-500' => $this->profit['net_profit'] >= 0, 'ring-rose-500' => $this->profit['net_profit'] < 0])>
                <p class="text-sm text-slate-500">Utilidad neta</p>
                <p @class([
                    'mt-1 text-2xl font-bold tabular-nums',
                    'text-emerald-600' => $this->profit['net_profit'] >= 0,
                    'text-rose-600' => $this->profit['net_profit'] < 0,
                ])>{{ $money($this->profit['net_profit']) }}</p>
                <p class="mt-0.5 text-xs text-slate-400">
                    margen {{ number_format($this->profit['margin'], 1) }}%
                </p>
            </x-card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <x-card title="Estado de resultados" flush>
                <dl class="divide-y divide-slate-100 text-sm">
                    {{-- La columna se lee de arriba a abajo: cada linea
                         parte de la anterior. --}}
                    @foreach (array_filter([
                        ['Ventas sin impuesto', $this->profit['gross_revenue'], 'text-slate-900'],
                        $this->profit['returns_net'] > 0
                            ? ['Devoluciones', -$this->profit['returns_net'], 'text-slate-600']
                            : null,
                        $this->profit['returns_net'] > 0
                            ? ['Ventas netas', $this->profit['revenue'], 'text-slate-900 font-semibold']
                            : null,
                        ['Costo de lo vendido', -$this->profit['cost'], 'text-slate-600'],
                        ['Utilidad bruta', $this->profit['gross_profit'], 'text-slate-900 font-semibold'],
                        ['Gastos', -$this->profit['expenses'], 'text-slate-600'],
                    ]) as [$label, $value, $class])
                        <div class="flex justify-between px-5 py-3">
                            <dt class="text-slate-600">{{ $label }}</dt>
                            <dd class="tabular-nums {{ $class }}">{{ $money($value) }}</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between px-5 py-3 bg-slate-50">
                        <dt class="font-semibold text-slate-900">Utilidad neta</dt>
                        <dd @class([
                            'tabular-nums font-bold',
                            'text-emerald-600' => $this->profit['net_profit'] >= 0,
                            'text-rose-600' => $this->profit['net_profit'] < 0,
                        ])>{{ $money($this->profit['net_profit']) }}</dd>
                    </div>
                </dl>
            </x-card>

            <div class="grid grid-cols-1 gap-3">
                <x-card title="Por cobrar y por pagar" flush>
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div class="flex justify-between px-5 py-3">
                            <dt class="text-slate-600">Te deben los clientes</dt>
                            <dd class="tabular-nums text-emerald-600">
                                {{ $money($this->balances['receivable']) }}
                            </dd>
                        </div>
                        <div class="flex justify-between px-5 py-3">
                            <dt class="text-slate-600">Debes a proveedores</dt>
                            <dd class="tabular-nums text-rose-600">
                                {{ $money($this->balances['payable']) }}
                            </dd>
                        </div>
                        <div class="flex justify-between px-5 py-3">
                            <dt class="text-slate-600">Compras por pagar del periodo</dt>
                            <dd class="tabular-nums text-slate-700">
                                {{ $money($this->purchases['pending']) }}
                            </dd>
                        </div>
                    </dl>
                </x-card>

                <x-card title="Gastos por categoria" flush>
                    @if ($this->expenses['by_category'] === [])
                        <p class="px-5 py-6 text-center text-sm text-slate-500">
                            Sin gastos en el periodo.
                        </p>
                    @else
                        <ul class="divide-y divide-slate-100 text-sm">
                            @foreach ($this->expenses['by_category'] as $row)
                                <li class="flex justify-between px-5 py-2.5">
                                    <span class="text-slate-600">{{ $row['name'] }}</span>
                                    <span class="tabular-nums text-slate-900">{{ $money($row['total']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </div>
        </div>

    {{-- ======================= Ventas ======================= --}}
    @elseif ($tab === 'ventas')
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <x-card>
                <p class="text-sm text-slate-500">Total</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $money($this->sales['total']) }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500">Tickets</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $this->sales['sales'] }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500">Ticket promedio</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $money($this->sales['average']) }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500">Utilidad</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">
                    {{ $money($this->sales['profit']) }}
                </p>
            </x-card>
        </div>

        {{-- Grafica hecha con divs: no hace falta cargar una libreria
             entera para ver la forma de la semana. --}}
        <x-card title="Ventas por dia" class="mb-3">
            {{-- La linea de base deja ver que los dias sin barra estan ahi
                 y no vendieron, en vez de parecer que faltan. --}}
            <div class="flex items-end gap-1 h-44 overflow-x-auto border-b border-slate-200">
                @foreach ($this->chart as $day)
                    <div class="flex-1 min-w-[14px] flex flex-col items-center justify-end h-full group">
                        <span class="text-[10px] text-slate-500 tabular-nums opacity-0 group-hover:opacity-100 transition">
                            {{ number_format($day['total'], 0) }}
                        </span>
                        <div class="w-full rounded-t bg-indigo-500/80 hover:bg-indigo-600 transition-all"
                             style="height: {{ $day['height'] }}%"
                             title="{{ $day['label'] }}: {{ $money($day['total']) }} ({{ $day['sales'] }})"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-1 mt-2 overflow-x-auto">
                @foreach ($this->chart as $day)
                    <span class="flex-1 min-w-[14px] text-center text-[10px] text-slate-400 truncate">
                        {{ $day['label'] }}
                    </span>
                @endforeach
            </div>
        </x-card>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <x-card title="Por forma de pago" flush>
                @if ($this->byPaymentMethod === [])
                    <p class="px-5 py-6 text-center text-sm text-slate-500">Sin ventas.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($this->byPaymentMethod as $row)
                            <li class="flex justify-between px-5 py-2.5">
                                <span class="text-slate-600">{{ $row['method'] }}</span>
                                <span class="tabular-nums">{{ $money($row['total']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Por cajero" flush>
                @if ($this->byUser === [])
                    <p class="px-5 py-6 text-center text-sm text-slate-500">Sin ventas.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($this->byUser as $row)
                            <li class="flex justify-between px-5 py-2.5">
                                <span class="text-slate-600">{{ $row['name'] }}</span>
                                <span class="tabular-nums">{{ $money($row['total']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Por categoria" flush>
                @if ($this->byCategory === [])
                    <p class="px-5 py-6 text-center text-sm text-slate-500">Sin ventas.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($this->byCategory as $row)
                            <li class="flex justify-between px-5 py-2.5">
                                <span class="text-slate-600">{{ $row['name'] }}</span>
                                <span class="tabular-nums">{{ $money($row['total']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

    {{-- ======================= Productos ======================= --}}
    @elseif ($tab === 'productos')
        @if ($this->topProducts === [])
            <x-card class="text-center py-16">
                <p class="text-4xl">▤</p>
                <p class="mt-3 font-medium text-slate-900">No se vendio nada en este periodo</p>
                <p class="mt-1 text-sm text-slate-500">Elige otro rango de fechas.</p>
            </x-card>
        @else
            <x-card title="Lo mas vendido" description="Ordenado por importe vendido" flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="px-5 py-3 font-medium">Producto</th>
                                <th class="px-5 py-3 font-medium text-right">Cantidad</th>
                                <th class="px-5 py-3 font-medium text-right">Vendido</th>
                                <th class="px-5 py-3 font-medium text-right hidden sm:table-cell">Utilidad</th>
                                <th class="px-5 py-3 font-medium text-right hidden lg:table-cell">Margen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->topProducts as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-5 py-3">
                                        <p class="text-slate-900">{{ $row['name'] }}</p>
                                        @if ($row['sku'])
                                            <p class="text-xs text-slate-400">{{ $row['sku'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600">
                                        {{ rtrim(rtrim(number_format($row['quantity'], 3), '0'), '.') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums font-medium">
                                        {{ $money($row['total']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums hidden sm:table-cell
                                               {{ $row['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                        {{ $money($row['profit']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-500 hidden lg:table-cell">
                                        {{ $row['total'] > 0 ? number_format($row['profit'] / $row['total'] * 100, 1).'%' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

    {{-- ======================= Inventario ======================= --}}
    @else
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
            <x-card>
                <p class="text-sm text-slate-500">Valor a costo</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $money($this->inventory['value']) }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500">Productos con existencia</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $this->inventory['products'] }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500">Unidades</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">
                    {{ rtrim(rtrim(number_format($this->inventory['units'], 3), '0'), '.') }}
                </p>
            </x-card>
        </div>

        {{-- Dinero detenido en el estante: es lo que nadie mira hasta que
             hace falta capital. --}}
        <x-card title="Inventario estancado"
                description="Con existencia y sin una sola venta en el periodo" flush>
            @if ($this->deadStock === [])
                <p class="px-5 py-8 text-center text-sm text-slate-500">
                    Todo lo que tienes en existencia tuvo movimiento. Bien ahi.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="px-5 py-3 font-medium">Producto</th>
                                <th class="px-5 py-3 font-medium text-right">Existencia</th>
                                <th class="px-5 py-3 font-medium text-right">Dinero detenido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->deadStock as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-5 py-3">
                                        <p class="text-slate-900">{{ $row['name'] }}</p>
                                        @if ($row['sku'])
                                            <p class="text-xs text-slate-400">{{ $row['sku'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600">
                                        {{ rtrim(rtrim(number_format($row['stock'], 3), '0'), '.') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-amber-600 font-medium">
                                        {{ $money($row['value']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endif
</div>
