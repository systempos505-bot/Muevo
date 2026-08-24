<div>
    <x-page-header title="Compra" :subtitle="$purchase->folio">
        <x-slot:actions>
            <a href="{{ route('purchases') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
            @if (! $purchase->isCancelled())
                @if (! $purchase->isPaid())
                    @can('purchases.create')
                        <x-button size="sm" wire:click="$set('showPayment', true)">Abonar</x-button>
                    @endcan
                @endif
                @can('purchases.void')
                    <x-button variant="danger" size="sm" wire:click="$set('showCancel', true)">Anular</x-button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($purchase->isCancelled())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3">
            <p class="text-sm font-medium text-rose-800">
                Compra anulada el {{ $purchase->cancelled_at?->format('d/m/Y H:i') }}
            </p>
            <p class="text-sm text-rose-700 mt-0.5">{{ $purchase->cancel_reason }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-card title="Productos recibidos" flush class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Producto</th>
                            <th class="px-5 py-3 font-medium text-right">Cantidad</th>
                            <th class="px-5 py-3 font-medium text-right">Costo</th>
                            <th class="px-5 py-3 font-medium text-right">Por unidad</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($purchase->items as $item)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900">{{ $item->description }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $item->sku }}
                                        @if ($item->lot_number)
                                            · Lote {{ $item->lot_number }}
                                        @endif
                                        @if ($item->expiry_date)
                                            · vence {{ $item->expiry_date->format('d/m/Y') }}
                                        @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-700">
                                    {{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}
                                    <span class="text-xs text-slate-400">{{ $item->unit_label }}</span>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600">
                                    {{ $currency?->symbol }}{{ number_format($item->unit_cost, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600">
                                    {{ $currency?->symbol }}{{ number_format($item->base_unit_cost, 4) }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($item->total, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="space-y-4">
            <x-card title="Resumen">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600">Proveedor</span>
                        <span class="font-medium">{{ $purchase->supplier?->name ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Factura</span>
                        <span>{{ $purchase->invoice_number ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Recibida</span>
                        <span>{{ $purchase->received_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Registro</span>
                        <span>{{ $purchase->user?->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Pago</span>
                        <span>{{ $purchase->payment_type === 'cash' ? 'Contado' : 'Credito' }}</span>
                    </div>
                    @if ($purchase->payment_type === 'credit' && $purchase->due_date)
                        <div class="flex justify-between">
                            <span class="text-slate-600">Se paga el</span>
                            <span @class(['text-rose-600 font-medium' => $purchase->isOverdue()])>
                                {{ $purchase->due_date->format('d/m/Y') }}
                            </span>
                        </div>
                    @endif

                    <div class="pt-2 mt-2 border-t border-slate-200 space-y-1">
                        <div class="flex justify-between text-slate-600">
                            <span>Subtotal</span>
                            <span class="tabular-nums">
                                {{ $currency?->symbol }}{{ number_format($purchase->subtotal, 2) }}
                            </span>
                        </div>
                        @if ($purchase->discount > 0)
                            <div class="flex justify-between text-emerald-700">
                                <span>Descuento</span>
                                <span class="tabular-nums">
                                    −{{ $currency?->symbol }}{{ number_format($purchase->discount, 2) }}
                                </span>
                            </div>
                        @endif
                        <div class="flex justify-between text-slate-600">
                            <span>Impuesto</span>
                            <span class="tabular-nums">
                                {{ $currency?->symbol }}{{ number_format($purchase->tax, 2) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-baseline pt-1 border-t border-slate-200">
                            <span class="font-medium text-slate-700">Total</span>
                            <span class="text-xl font-bold tabular-nums">
                                {{ $currency?->symbol }}{{ number_format($purchase->total, 2) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Abonado</span>
                            <span class="tabular-nums text-emerald-700">
                                {{ $currency?->symbol }}{{ number_format($purchase->paid, 2) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-medium text-slate-700">Saldo</span>
                            <span @class([
                                'font-bold tabular-nums',
                                'text-emerald-700' => $purchase->isPaid(),
                                'text-amber-600' => ! $purchase->isPaid(),
                            ])>{{ $currency?->symbol }}{{ number_format($purchase->balance(), 2) }}</span>
                        </div>
                    </div>
                </div>
            </x-card>

            @if ($purchase->payments->isNotEmpty())
                <x-card title="Abonos" flush>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($purchase->payments as $payment)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-900">
                                        {{ $payment->created_at->format('d/m/Y') }}
                                    </p>
                                    @if ($payment->reference)
                                        <p class="text-xs text-slate-500">{{ $payment->reference }}</p>
                                    @endif
                                </div>
                                <span class="font-medium tabular-nums text-emerald-700 shrink-0">
                                    {{ $currency?->symbol }}{{ number_format($payment->amount, 2) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    </div>

    @if ($showPayment)
        <x-modal title="Abonar a la compra" wire="showPayment">
            <form wire:submit="pay" class="space-y-4">
                <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-sm text-slate-600">Saldo pendiente</p>
                    <p class="text-2xl font-bold tabular-nums mt-0.5">
                        {{ $currency?->symbol }}{{ number_format($purchase->balance(), 2) }}
                    </p>
                </div>

                <x-input label="Monto del abono" type="number" step="0.01" min="0"
                         wire:model="paymentAmount" inputmode="decimal"
                         :error="$errors->first('paymentAmount')" autofocus />

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Se paga con</label>
                    <select wire:model="paymentMethodId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-input label="Referencia" wire:model="paymentReference" placeholder="Opcional" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showPayment', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Registrar abono</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showCancel)
        <x-modal title="Anular compra" wire="showCancel">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-slate-600">
                    La mercancia sale del inventario y la deuda con el proveedor se cancela.
                    Solo se puede si todavia hay existencia suficiente.
                </p>

                <x-input label="Motivo" wire:model="cancelReason"
                         placeholder="Llego mercancia equivocada"
                         :error="$errors->first('cancelReason')" autofocus />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showCancel', false)">Cancelar</x-button>
                    <x-button type="submit" variant="danger" class="flex-1">Anular compra</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
