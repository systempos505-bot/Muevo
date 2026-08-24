<div>
    <x-page-header title="Kardex" :subtitle="$product->name . ' · ' . $product->sku">
        <x-slot:actions>
            <a href="{{ route('inventory') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Existencia actual</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ rtrim(rtrim(number_format($stock, 3), '0'), '.') }}
                <span class="text-sm font-normal text-slate-400">{{ $product->baseUnit?->code }}</span>
            </p>
        </x-card>

        <div class="sm:col-span-2 flex flex-col sm:flex-row gap-3">
            <select wire:model.live="branchId"
                    class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                <option value="">Todas las sucursales</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="type"
                    class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                <option value="">Todos los movimientos</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($movements->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">◔</p>
            <p class="mt-3 font-medium text-slate-900">Sin movimientos</p>
            <p class="mt-1 text-sm text-slate-500">
                Este producto todavia no registra entradas ni salidas.
            </p>
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Movimiento</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Sucursal</th>
                            <th class="px-5 py-3 font-medium text-right">Cantidad</th>
                            <th class="px-5 py-3 font-medium text-right">Saldo</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Motivo</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($movements as $movement)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $movement->created_at?->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-5 py-3">
                                    <span @class([
                                        'px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700' => $movement->isEntry(),
                                        'bg-rose-100 text-rose-700' => ! $movement->isEntry(),
                                    ])>{{ $movement->typeLabel() }}</span>

                                    @if ($movement->lot)
                                        <p class="text-xs text-slate-500 mt-1">
                                            Lote {{ $movement->lot->lot_number }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $movement->branch->name }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-emerald-700' => $movement->isEntry(),
                                        'text-rose-600' => ! $movement->isEntry(),
                                    ])>
                                        {{ $movement->isEntry() ? '+' : '' }}{{ rtrim(rtrim(number_format($movement->quantity, 3), '0'), '.') }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-slate-900 tabular-nums">
                                    {{ rtrim(rtrim(number_format($movement->balance, 3), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-slate-500 hidden lg:table-cell max-w-xs truncate">
                                    {{ $movement->reason ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-500 hidden lg:table-cell">
                                    {{ $movement->user?->name ?? 'Sistema' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $movements->links() }}</div>
    @endif
</div>
