@php
    $badges = [
        'pending' => 'bg-amber-50 text-amber-700',
        'approved' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-slate-100 text-slate-600',
        'converted' => 'bg-indigo-50 text-indigo-700',
    ];
@endphp

<div>
    <x-page-header title="Cotizaciones"
                   :subtitle="$this->waiting . ' esperando respuesta'">
        <x-slot:actions>
            @can('quotes.manage')
                <a href="{{ route('quotes.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                          px-3 py-1.5 text-sm bg-indigo-600 text-white hover:bg-indigo-700">
                    + Cotizacion
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($this->expired > 0)
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <strong>{{ $this->expired }}</strong>
            {{ $this->expired === 1 ? 'cotizacion se paso' : 'cotizaciones se pasaron' }}
            de su fecha sin cerrarse. El precio ya no esta comprometido.
            <button type="button" wire:click="$set('status', 'expired')"
                    class="underline font-medium ml-1">Verlas</button>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por folio, cliente o telefono"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todas</option>
            @foreach (\App\Models\Quote::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
            <option value="expired">Vencidas</option>
        </select>
    </div>

    @if ($quotes->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">✎</p>
            <p class="mt-3 font-medium text-slate-900">
                {{ $search || $status ? 'Ninguna coincide con ese filtro' : 'Todavia no hay cotizaciones' }}
            </p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                Una cotizacion es un precio por escrito con fecha de caducidad. No
                descuenta inventario ni cobra nada hasta que se convierte en venta.
            </p>
            @can('quotes.manage')
                @if (! $search && ! $status)
                    <div class="mt-5">
                        <a href="{{ route('quotes.create') }}" wire:navigate
                           class="inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                                  px-4 py-2.5 text-sm bg-indigo-600 text-white hover:bg-indigo-700">
                            Crear la primera
                        </a>
                    </div>
                @endif
            @endcan
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Folio</th>
                            <th class="px-5 py-3 font-medium">Cliente</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Vigencia</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                            <th class="px-5 py-3 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($quotes as $quote)
                            <tr class="hover:bg-slate-50" wire:key="q-{{ $quote->id }}">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('quotes.show', $quote) }}" wire:navigate
                                       class="text-indigo-600 hover:underline font-medium">
                                        {{ $quote->folio }}
                                    </a>
                                    <p class="text-xs text-slate-400">
                                        {{ $quote->created_at->format('d/m/Y') }}
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-slate-700">
                                    {{ $quote->customerLabel() }}
                                    @if ($quote->customer_phone)
                                        <p class="text-xs text-slate-400">{{ $quote->customer_phone }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell whitespace-nowrap">
                                    {{ $quote->valid_until->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900">
                                    {{ $currency?->symbol }}{{ number_format($quote->total, 2) }}
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-block rounded-full px-2.5 py-1 text-xs font-medium
                                        {{ $quote->isExpired() ? 'bg-rose-50 text-rose-700' : ($badges[$quote->status] ?? 'bg-slate-100 text-slate-600') }}">
                                        {{ $quote->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $quotes->links() }}</div>
    @endif
</div>
