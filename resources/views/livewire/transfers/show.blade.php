@php $symbol = $currency?->symbol ?? '$'; @endphp

<div>
    <x-page-header :title="'Traspaso ' . $transfer->folio"
                   :subtitle="$transfer->fromBranch?->name . ' → ' . $transfer->toBranch?->name">
        <x-slot:actions>
            <a href="{{ route('transfers') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>

            @can('inventory.adjust')
                {{-- Solo se ofrece la accion que toca: un traspaso en
                     camino se recibe, no se manda otra vez. --}}
                @if ($transfer->isDraft())
                    <x-button variant="secondary" size="sm" wire:click="send">Enviar</x-button>
                    <x-button size="sm" wire:click="sendAndReceive">Enviar y recibir</x-button>
                @elseif ($transfer->isInTransit())
                    <x-button size="sm" wire:click="openReceive">Recibir</x-button>
                @endif

                @unless ($transfer->isReceived() || $transfer->isCancelled())
                    <x-button variant="danger" size="sm" wire:click="$set('showCancel', true)">
                        Cancelar
                    </x-button>
                @endunless
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Estado --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Estado</p>
            <p @class([
                'mt-1 text-xl font-bold',
                'text-slate-700' => $transfer->isDraft(),
                'text-amber-600' => $transfer->isInTransit(),
                'text-emerald-600' => $transfer->isReceived(),
                'text-rose-600' => $transfer->isCancelled(),
            ])>{{ $transfer->statusLabel() }}</p>
            <p class="mt-0.5 text-xs text-slate-400">
                @if ($transfer->isReceived())
                    Recibido el {{ $transfer->received_at?->format('d/m/Y H:i') }}
                    por {{ $transfer->receiver?->name }}
                @elseif ($transfer->isInTransit())
                    Salio el {{ $transfer->sent_at?->format('d/m/Y H:i') }}
                    con {{ $transfer->sender?->name }}
                @elseif ($transfer->isCancelled())
                    {{ $transfer->cancel_reason }}
                @else
                    Armado por {{ $transfer->creator?->name }}
                @endif
            </p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Productos</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-slate-900">
                {{ $transfer->items->count() }}
            </p>
            <p class="mt-0.5 text-xs text-slate-400 tabular-nums">
                {{ rtrim(rtrim(number_format($transfer->items->sum('quantity_sent'), 3), '0'), '.') }}
                unidades
            </p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Valor a costo</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-slate-900">
                {{ $symbol }}{{ number_format($transfer->total_cost, 2) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-400">
                {{ $transfer->isDraft() ? 'Se calcula al enviar' : 'Al costo del origen' }}
            </p>
        </x-card>
    </div>

    @if ($transfer->isInTransit())
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3">
            <p class="text-sm text-amber-800">
                Esta mercancia ya salio de {{ $transfer->fromBranch?->name }} y todavia no
                llega a {{ $transfer->toBranch?->name }}. Mientras tanto no cuenta como
                existencia en ninguna de las dos.
            </p>
        </div>
    @endif

    @if ($transfer->isReceived() && $transfer->shortfall() > 0)
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3">
            <p class="text-sm font-medium text-rose-800">
                {{ $transfer->shortfallLabel() }} en el camino
            </p>
            <p class="text-sm text-rose-700 mt-0.5">
                Salieron del origen y nunca llegaron al destino.
            </p>
        </div>
    @endif

    <x-card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-5 py-3 font-medium">Producto</th>
                        <th class="px-5 py-3 font-medium text-right">Enviado</th>
                        @if ($transfer->isReceived())
                            <th class="px-5 py-3 font-medium text-right">Recibido</th>
                        @elseif ($transfer->isDraft())
                            <th class="px-5 py-3 font-medium text-right hidden sm:table-cell">En el origen</th>
                        @endif
                        <th class="px-5 py-3 font-medium text-right hidden lg:table-cell">Costo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($transfer->items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td class="px-5 py-3">
                                <p class="text-slate-900">{{ $item->description }}</p>
                                @if ($item->sku)
                                    <p class="text-xs text-slate-400">{{ $item->sku }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">
                                {{ rtrim(rtrim(number_format($item->quantity_sent, 3), '0'), '.') }}
                                <span class="text-xs text-slate-400">{{ $item->unit_label }}</span>
                            </td>

                            @if ($transfer->isReceived())
                                <td class="px-5 py-3 text-right tabular-nums
                                           {{ $item->shortfall() > 0 ? 'text-rose-600 font-medium' : 'text-slate-700' }}">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity_received, 3), '0'), '.') }}
                                    @if ($item->shortfall() > 0)
                                        <p class="text-xs text-rose-500">
                                            {{ $item->shortfall() == 1.0 ? 'falto 1' : 'faltaron '.rtrim(rtrim(number_format($item->shortfall(), 3), '0'), '.') }}
                                        </p>
                                    @endif
                                </td>
                            @elseif ($transfer->isDraft())
                                @php $available = $this->availability[$item->id] ?? 0; @endphp
                                <td class="px-5 py-3 text-right tabular-nums hidden sm:table-cell
                                           {{ $item->quantity_sent > $available ? 'text-rose-600 font-medium' : 'text-slate-500' }}">
                                    {{ rtrim(rtrim(number_format($available, 3), '0'), '.') }}
                                </td>
                            @endif

                            <td class="px-5 py-3 text-right tabular-nums text-slate-500 hidden lg:table-cell">
                                {{ $symbol }}{{ number_format($item->unit_cost, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($transfer->notes)
        <p class="mt-3 text-sm text-slate-500">{{ $transfer->notes }}</p>
    @endif

    {{-- ==================== Recepcion ==================== --}}
    @if ($showReceive)
        <x-modal title="Recibir traspaso" wire="showReceive">
            <form wire:submit="receive" class="space-y-4">
                <p class="text-sm text-slate-600">
                    Confirma cuanto llego de cada producto. Lo que falte no entra a
                    ningun lado: ya salio del origen y nunca llego.
                </p>

                <div class="space-y-2">
                    @foreach ($transfer->items as $item)
                        <div wire:key="rec-{{ $item->id }}"
                             class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900 truncate">{{ $item->description }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">
                                    salieron {{ rtrim(rtrim(number_format($item->quantity_sent, 3), '0'), '.') }}
                                    {{ $item->unit_label }}
                                </p>
                            </div>

                            <input type="number" step="0.001" min="0" max="{{ $item->quantity_sent }}"
                                   inputmode="decimal"
                                   wire:model="receivedLines.{{ $item->id }}"
                                   class="w-20 shrink-0 text-center rounded-lg border border-slate-300 py-1.5 text-sm
                                          tabular-nums focus:ring-2 focus:ring-indigo-500">
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showReceive', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Confirmar recepcion</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- ==================== Cancelacion ==================== --}}
    @if ($showCancel)
        <x-modal title="Cancelar traspaso" wire="showCancel">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-slate-600">
                    @if ($transfer->isInTransit())
                        La mercancia regresa a {{ $transfer->fromBranch?->name }}: esta en
                        algun lado, y ese lado es de donde salio.
                    @else
                        Este traspaso todavia no ha movido nada, asi que solo queda
                        marcado como cancelado.
                    @endif
                </p>

                <x-input label="Motivo" wire:model="cancelReason"
                         placeholder="Se cayo el envio de hoy"
                         :error="$errors->first('cancelReason')" autofocus />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showCancel', false)">Volver</x-button>
                    <x-button type="submit" variant="danger" class="flex-1">
                        Cancelar traspaso
                    </x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
