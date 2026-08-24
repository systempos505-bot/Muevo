<div>
    <x-page-header :title="$account->name" :subtitle="$account->typeLabel()">
        <x-slot:actions>
            <a href="{{ route('accounts') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Saldo actual</p>
            <p @class([
                'mt-1 text-2xl font-bold tabular-nums',
                'text-rose-600' => $account->balance < 0,
            ])>{{ $account->currency?->symbol }}{{ number_format($account->balance, 2) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Entradas del periodo</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-700">
                {{ $account->currency?->symbol }}{{ number_format($totalIn, 2) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Salidas del periodo</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-rose-600">
                {{ $account->currency?->symbol }}{{ number_format($totalOut, 2) }}
            </p>
        </x-card>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <select wire:model.live="source"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los movimientos</option>
            @foreach ($sources as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <input type="date" wire:model.live="from"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
        <input type="date" wire:model.live="to"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
    </div>

    @if ($movements->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">◫</p>
            <p class="mt-3 font-medium text-slate-900">Sin movimientos</p>
            <p class="mt-1 text-sm text-slate-500">Esta cuenta todavia no registra entradas ni salidas.</p>
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Concepto</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Origen</th>
                            <th class="px-5 py-3 font-medium text-right">Monto</th>
                            <th class="px-5 py-3 font-medium text-right">Saldo</th>
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
                                    <p class="text-slate-900">{{ $movement->description }}</p>
                                    @if ($movement->reference)
                                        <p class="text-xs text-slate-500">{{ $movement->reference }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 hidden sm:table-cell">
                                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-xs">
                                        {{ $movement->sourceLabel() }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-medium',
                                        'text-emerald-700' => $movement->isEntry(),
                                        'text-rose-600' => ! $movement->isEntry(),
                                    ])>
                                        {{ $movement->isEntry() ? '+' : '−' }}{{ $account->currency?->symbol }}{{ number_format($movement->amount, 2) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums text-slate-900">
                                    {{ $account->currency?->symbol }}{{ number_format($movement->balance, 2) }}
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
