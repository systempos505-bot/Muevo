<div>
    <x-page-header :title="$customer->name"
                   :subtitle="$customer->customerType?->name ?? 'Cliente'">
        <x-slot:actions>
            <a href="{{ route('customers') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Volver</x-button>
            </a>
            @if ($customer->balance > 0)
                @can('customers.edit')
                    <x-button size="sm" wire:click="openPayment">Recibir abono</x-button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Cifras de la cuenta --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Saldo</p>
            <p @class([
                'mt-1 text-2xl font-semibold tabular-nums',
                'text-amber-600' => $customer->balance > 0,
                'text-slate-900' => $customer->balance <= 0,
            ])>{{ $currency?->symbol }}{{ number_format($customer->balance, 2) }}</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Credito disponible</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                @if (! $customer->credit_enabled)
                    <span class="text-base font-normal text-slate-400">Sin credito</span>
                @elseif ($this->available === null)
                    <span class="text-base font-normal text-slate-500">Sin limite</span>
                @else
                    {{ $currency?->symbol }}{{ number_format($this->available, 2) }}
                @endif
            </p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Compras</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $this->totals['sales'] }}</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Ticket promedio</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ $currency?->symbol }}{{ number_format($this->totals['average'], 2) }}
            </p>
        </x-card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Estado de cuenta --}}
        <x-card title="Estado de cuenta"
                description="Cargos y abonos, del mas reciente al mas antiguo"
                flush class="lg:col-span-2">
            @if ($this->statement === [])
                <p class="px-5 py-10 text-center text-sm text-slate-500">
                    Este cliente no tiene movimientos de credito.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="px-5 py-3 font-medium">Fecha</th>
                                <th class="px-5 py-3 font-medium">Movimiento</th>
                                <th class="px-5 py-3 font-medium text-right">Cargo</th>
                                <th class="px-5 py-3 font-medium text-right">Abono</th>
                                <th class="px-5 py-3 font-medium text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->statement as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                        {{ $row['date']->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <p class="text-slate-900">{{ $row['label'] }}</p>
                                        @if ($row['reference'])
                                            <p class="text-xs text-slate-500">{{ $row['reference'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums">
                                        @if ($row['charge'] > 0)
                                            <span class="text-slate-700">
                                                {{ $currency?->symbol }}{{ number_format($row['charge'], 2) }}
                                            </span>
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums">
                                        @if ($row['payment'] > 0)
                                            <span class="text-emerald-700">
                                                {{ $currency?->symbol }}{{ number_format($row['payment'], 2) }}
                                            </span>
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right font-medium tabular-nums text-slate-900">
                                        {{ $currency?->symbol }}{{ number_format($row['balance'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <div class="space-y-4">
            {{-- Datos --}}
            <x-card title="Datos">
                <dl class="space-y-2 text-sm">
                    @foreach ([
                        'Identificacion' => $customer->tax_id,
                        'Telefono' => $customer->phone,
                        'WhatsApp' => $customer->whatsapp,
                        'Correo' => $customer->email,
                        'Direccion' => $customer->address,
                    ] as $label => $value)
                        @if ($value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-600 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right truncate">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach

                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Lista de precios</dt>
                        <dd class="text-slate-900">
                            {{ $customer->priceList?->name ?? $customer->customerType?->priceList?->name ?? 'La de mostrador' }}
                        </dd>
                    </div>

                    @if ($customer->credit_enabled)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Plazo</dt>
                            <dd class="text-slate-900">
                                {{ $customer->credit_days > 0 ? $customer->credit_days.' dias' : 'Sin plazo' }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($customer->notes)
                    <p class="mt-3 pt-3 border-t border-slate-100 text-sm text-slate-600">
                        {{ $customer->notes }}
                    </p>
                @endif
            </x-card>

            {{-- Ultimas compras --}}
            <x-card title="Ultimas compras" flush>
                @if ($this->sales->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">
                        Todavia no ha comprado nada.
                    </p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($this->sales as $sale)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('sales.show', $sale) }}" wire:navigate
                                       class="text-sm font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $sale->folio }}
                                    </a>
                                    <p class="text-xs text-slate-500">
                                        {{ $sale->created_at->format('d/m/Y') }}
                                        @if ($sale->isCancelled())
                                            · <span class="text-rose-600">anulada</span>
                                        @endif
                                    </p>
                                </div>
                                <span class="font-medium tabular-nums shrink-0">
                                    {{ $currency?->symbol }}{{ number_format($sale->total, 2) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            {{-- Abonos --}}
            @if ($payments->isNotEmpty())
                <x-card title="Ultimos abonos" flush>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($payments as $payment)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-900">
                                        {{ $payment->created_at->format('d/m/Y') }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ $payment->paymentMethod?->name }}
                                        @if ($payment->user) · {{ $payment->user->name }} @endif
                                    </p>
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
        <x-modal title="Recibir abono" wire="showPayment">
            <form wire:submit="pay" class="space-y-4">
                <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-sm text-slate-600">{{ $customer->name }} debe</p>
                    <p class="text-2xl font-bold tabular-nums mt-0.5">
                        {{ $currency?->symbol }}{{ number_format($customer->balance, 2) }}
                    </p>
                </div>

                <x-input label="Monto del abono" type="number" step="0.01" min="0"
                         wire:model="paymentAmount" inputmode="decimal"
                         :error="$errors->first('paymentAmount')" autofocus />

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Recibido con</label>
                    <select wire:model="paymentMethodId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                    {{-- Un abono en efectivo entra al cajon, asi que cuenta
                         en el arqueo del turno abierto. --}}
                    <p class="text-xs text-slate-500">
                        Si es en efectivo, se suma al arqueo de la caja abierta.
                    </p>
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
</div>
