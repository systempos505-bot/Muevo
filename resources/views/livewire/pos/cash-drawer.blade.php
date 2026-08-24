<div>
    <x-page-header title="Caja" :subtitle="$this->terminal?->name ?? 'Sin caja configurada'">
        <x-slot:actions>
            @if ($this->shift)
                <x-button variant="secondary" size="sm" wire:click="$set('showMovement', true)">
                    Movimiento
                </x-button>
                @can('cash.close')
                    <x-button size="sm" wire:click="$set('showClose', true)">Cerrar caja</x-button>
                @endcan
            @else
                @can('cash.open')
                    <x-button size="sm" wire:click="$set('showOpen', true)">Abrir caja</x-button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->shift === null)
        <x-card class="text-center py-16">
            <p class="text-4xl">▦</p>
            <p class="mt-3 font-medium text-slate-900">La caja esta cerrada</p>
            <p class="mt-1 text-sm text-slate-500">
                Abrela con el efectivo con el que empiezas el turno.
            </p>
            @can('cash.open')
                <div class="mt-5">
                    <x-button wire:click="$set('showOpen', true)">Abrir caja</x-button>
                </div>
            @endcan
        </x-card>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            {{-- Arqueo --}}
            <x-card title="Efectivo en caja" description="Lo que deberia haber ahora mismo" class="lg:col-span-2">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600">Fondo inicial</span>
                        <span class="tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->summary['opening'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Ventas en efectivo</span>
                        <span class="tabular-nums text-emerald-700">
                            +{{ $currency?->symbol }}{{ number_format($this->summary['cash_sales'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Cambio entregado</span>
                        <span class="tabular-nums text-rose-600">
                            −{{ $currency?->symbol }}{{ number_format($this->summary['change'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Entradas</span>
                        <span class="tabular-nums text-emerald-700">
                            +{{ $currency?->symbol }}{{ number_format($this->summary['cash_in'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Salidas</span>
                        <span class="tabular-nums text-rose-600">
                            −{{ $currency?->symbol }}{{ number_format($this->summary['cash_out'], 2) }}
                        </span>
                    </div>

                    <div class="flex justify-between items-baseline pt-2 mt-2 border-t border-slate-200">
                        <span class="font-medium text-slate-700">Debe haber</span>
                        <span class="text-2xl font-bold tabular-nums text-slate-900">
                            {{ $currency?->symbol }}{{ number_format($this->summary['expected'], 2) }}
                        </span>
                    </div>
                </div>
            </x-card>

            {{-- Turno --}}
            <x-card title="Turno abierto">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600">Folio</span>
                        <span class="font-medium">{{ $this->shift->folio }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Cajero</span>
                        <span>{{ $this->shift->user?->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Abierto</span>
                        <span>{{ $this->shift->opened_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between pt-2 mt-2 border-t border-slate-200">
                        <span class="text-slate-600">Ventas</span>
                        <span class="font-medium tabular-nums">{{ $this->summary['sales_count'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Total vendido</span>
                        <span class="font-medium tabular-nums">
                            {{ $currency?->symbol }}{{ number_format($this->summary['sales_total'], 2) }}
                        </span>
                    </div>
                </div>
            </x-card>
        </div>

        {{-- Movimientos --}}
        @if ($movements->isNotEmpty())
            <x-card title="Movimientos de efectivo" flush class="mt-4">
                <ul class="divide-y divide-slate-100">
                    @foreach ($movements as $movement)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900 truncate">{{ $movement->reason }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $movement->created_at->format('d/m/Y H:i') }}
                                    · {{ $movement->user?->name }}
                                </p>
                            </div>
                            <span @class([
                                'font-medium tabular-nums shrink-0',
                                'text-emerald-700' => $movement->type === 'in',
                                'text-rose-600' => $movement->type === 'out',
                            ])>
                                {{ $movement->type === 'in' ? '+' : '−' }}{{ $currency?->symbol }}{{ number_format($movement->amount, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    @endif

    {{-- Cortes anteriores --}}
    @if ($recentShifts->isNotEmpty())
        <x-card title="Cortes anteriores" flush class="mt-4">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium">Cajero</th>
                            <th class="px-5 py-3 font-medium">Cerrado</th>
                            <th class="px-5 py-3 font-medium text-right">Esperado</th>
                            <th class="px-5 py-3 font-medium text-right">Contado</th>
                            <th class="px-5 py-3 font-medium text-right">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recentShifts as $past)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3 font-medium text-slate-900">{{ $past->folio }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $past->user?->name }}</td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $past->closed_at?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($past->expected_amount, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($past->counted_amount, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-rose-600' => $past->difference < 0,
                                        'text-amber-600' => $past->difference > 0,
                                        'text-emerald-700' => $past->difference == 0,
                                    ])>
                                        {{ $currency?->symbol }}{{ number_format($past->difference, 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    {{-- ================= MODALES ================= --}}

    @if ($showOpen)
        <x-modal title="Abrir caja" wire="showOpen">
            <form wire:submit="open" class="space-y-4">
                <p class="text-sm text-slate-600">
                    Cuenta el efectivo con el que empiezas. Sirve para cuadrar al cerrar.
                </p>
                <x-input label="Fondo inicial" type="number" step="0.01" min="0"
                         wire:model="openingAmount" inputmode="decimal" placeholder="0.00"
                         :error="$errors->first('openingAmount')" autofocus />
                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showOpen', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Abrir caja</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showMovement)
        <x-modal title="Movimiento de efectivo" wire="showMovement">
            <form wire:submit="saveMovement" class="space-y-4">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="$set('movementType', 'in')"
                        @class([
                            'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                            'border-emerald-500 bg-emerald-50 text-emerald-700' => $movementType === 'in',
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $movementType !== 'in',
                        ])>Entrada</button>
                    <button type="button" wire:click="$set('movementType', 'out')"
                        @class([
                            'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                            'border-rose-500 bg-rose-50 text-rose-700' => $movementType === 'out',
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $movementType !== 'out',
                        ])>Salida</button>
                </div>

                <x-input label="Monto" type="number" step="0.01" min="0"
                         wire:model="movementAmount" inputmode="decimal"
                         :error="$errors->first('movementAmount')" autofocus />

                <x-input label="Motivo" wire:model="movementReason"
                         placeholder="Pago de mensajeria"
                         :error="$errors->first('movementReason')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showMovement', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Registrar</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showClose)
        <x-modal title="Cerrar caja" wire="showClose">
            <form wire:submit="close" class="space-y-4">
                <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-sm text-slate-600">Segun el sistema debe haber</p>
                    <p class="text-2xl font-bold tabular-nums text-slate-900 mt-0.5">
                        {{ $currency?->symbol }}{{ number_format($this->summary['expected'], 2) }}
                    </p>
                </div>

                <x-input label="Efectivo contado" type="number" step="0.01" min="0"
                         wire:model.live="countedAmount" inputmode="decimal" placeholder="0.00"
                         hint="Cuenta el cajon antes de escribir."
                         :error="$errors->first('countedAmount')" autofocus />

                @if ($countedAmount !== null)
                    @php $diff = round((float) $countedAmount - $this->summary['expected'], 2); @endphp
                    <div @class([
                        'rounded-lg px-4 py-3 text-sm',
                        'bg-emerald-50 border border-emerald-200 text-emerald-800' => $diff == 0,
                        'bg-rose-50 border border-rose-200 text-rose-800' => $diff < 0,
                        'bg-amber-50 border border-amber-200 text-amber-800' => $diff > 0,
                    ])>
                        @if ($diff == 0)
                            La caja cuadra exactamente.
                        @elseif ($diff < 0)
                            Faltan {{ $currency?->symbol }}{{ number_format(abs($diff), 2) }}.
                        @else
                            Sobran {{ $currency?->symbol }}{{ number_format($diff, 2) }}.
                        @endif
                    </div>
                @endif

                <x-input label="Notas" wire:model="closeNotes" placeholder="Opcional" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showClose', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Cerrar caja</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
