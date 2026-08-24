<div>
    <x-page-header title="Cuentas de pago" subtitle="Donde vive el dinero del negocio">
        <x-slot:actions>
            @can('finance.manage')
                <x-button variant="secondary" size="sm" wire:click="openTransfer">Trasladar</x-button>
                <x-button size="sm" wire:click="create">+ Cuenta</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Total y flujo del mes --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <x-card class="lg:col-span-2">
            <p class="text-sm text-slate-500">Dinero disponible</p>
            <p class="mt-1 text-3xl font-bold tabular-nums">
                {{ $primary?->symbol }}{{ number_format($this->totalBalance, 2) }}
            </p>
            <p class="text-xs text-slate-500 mt-1">Suma de todas las cuentas activas</p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Entro este mes</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-700">
                {{ $primary?->symbol }}{{ number_format($flow['in'], 2) }}
            </p>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">Salio este mes</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-rose-600">
                {{ $primary?->symbol }}{{ number_format($flow['out'], 2) }}
            </p>
        </x-card>
    </div>

    {{-- Cuentas --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
        @foreach ($this->accounts as $account)
            <x-card wire:key="acc-{{ $account->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p @class([
                                'font-medium',
                                'text-slate-900' => $account->status === 'active',
                                'text-slate-400 line-through' => $account->status !== 'active',
                            ])>{{ $account->name }}</p>

                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-xs font-medium">
                                {{ $account->typeLabel() }}
                            </span>

                            @if ($account->is_default)
                                <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-xs font-medium">
                                    Principal
                                </span>
                            @endif
                        </div>

                        @if ($account->reference)
                            <p class="text-xs text-slate-500 mt-0.5">{{ $account->reference }}</p>
                        @endif
                    </div>

                    <div class="text-right shrink-0">
                        <p @class([
                            'text-xl font-bold tabular-nums',
                            'text-rose-600' => $account->balance < 0,
                            'text-slate-900' => $account->balance >= 0,
                        ])>
                            {{ $account->currency?->symbol }}{{ number_format($account->balance, 2) }}
                        </p>
                        {{-- Si la cuenta no esta en la moneda principal, se
                             muestra tambien el equivalente. --}}
                        @if ($account->currency && ! $account->currency->is_primary)
                            <p class="text-xs text-slate-500">
                                ≈ {{ $primary?->symbol }}{{ number_format($account->balanceInPrimary(), 2) }}
                            </p>
                        @endif
                    </div>
                </div>

                @can('finance.manage')
                    <div class="flex items-center gap-1 mt-3 pt-3 border-t border-slate-100 flex-wrap">
                        <button wire:click="openMovement('{{ $account->id }}', 'in')"
                                class="px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 rounded">
                            Entrada
                        </button>
                        <button wire:click="openMovement('{{ $account->id }}', 'out')"
                                class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                            Salida
                        </button>
                        <a href="{{ route('accounts.show', ['accountId' => $account->id]) }}" wire:navigate
                           class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                            Movimientos
                        </a>
                        <button wire:click="edit('{{ $account->id }}')"
                                class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                            Editar
                        </button>
                        <button wire:click="toggleStatus('{{ $account->id }}')"
                                class="ml-auto px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 rounded">
                            {{ $account->status === 'active' ? 'Desactivar' : 'Activar' }}
                        </button>
                    </div>
                @endcan
            </x-card>
        @endforeach
    </div>

    {{-- De donde vino y a donde se fue --}}
    @if ($flow['by_source'] !== [])
        <x-card title="Movimiento del mes" description="De donde entro y en que salio el dinero">
            <div class="space-y-2">
                @foreach ($flow['by_source'] as $source => $amount)
                    @php
                        $labels = [
                            'sale' => 'Ventas',
                            'purchase' => 'Compras',
                            'expense' => 'Gastos',
                            'customer_payment' => 'Abonos de clientes',
                            'supplier_payment' => 'Pagos a proveedores',
                            'manual' => 'Movimientos manuales',
                        ];
                    @endphp
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-slate-600">{{ $labels[$source] ?? ucfirst($source) }}</span>
                        <span @class([
                            'font-medium tabular-nums',
                            'text-emerald-700' => $amount > 0,
                            'text-rose-600' => $amount < 0,
                            'text-slate-500' => $amount == 0,
                        ])>
                            {{ $amount >= 0 ? '+' : '−' }}{{ $primary?->symbol }}{{ number_format(abs($amount), 2) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    {{-- ================= MODALES ================= --}}

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar cuenta' : 'Nueva cuenta'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Banco Atlantida"
                         :error="$errors->first('name')" autofocus />

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tipo</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Models\Account::TYPES as $value => $label)
                            <button type="button" wire:click="$set('type', '{{ $value }}')"
                                @class([
                                    'px-3 py-2.5 rounded-lg border text-sm font-medium transition',
                                    'border-indigo-500 bg-indigo-50 text-indigo-700' => $type === $value,
                                    'border-slate-300 text-slate-700 hover:bg-slate-50' => $type !== $value,
                                ])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Moneda</label>
                    <select wire:model="currencyId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">
                                {{ $currency->code }} — {{ $currency->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('currencyId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <x-input label="Referencia" wire:model="reference"
                         placeholder="Numero de cuenta, opcional"
                         :error="$errors->first('reference')" />

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showMovement)
        <x-modal :title="$movementDirection === 'in' ? 'Entrada de dinero' : 'Salida de dinero'"
                 wire="showMovement">
            <form wire:submit="saveMovement" class="space-y-4">
                <x-input label="Monto" type="number" step="0.01" min="0"
                         wire:model="movementAmount" inputmode="decimal"
                         :error="$errors->first('movementAmount')" autofocus />

                <x-input label="Concepto" wire:model="movementDescription"
                         placeholder="Deposito del dueno"
                         hint="Queda en el historial de la cuenta."
                         :error="$errors->first('movementDescription')" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showMovement', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Registrar</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showTransfer)
        <x-modal title="Trasladar dinero" wire="showTransfer">
            <form wire:submit="saveTransfer" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Sale de</label>
                    <select wire:model.live="fromAccountId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Elegir cuenta</option>
                        @foreach ($this->accounts->where('status', 'active') as $account)
                            <option value="{{ $account->id }}">
                                {{ $account->name }}
                                ({{ $account->currency?->symbol }}{{ number_format($account->balance, 2) }})
                            </option>
                        @endforeach
                    </select>
                    @error('fromAccountId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Entra a</label>
                    <select wire:model.live="toAccountId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Elegir cuenta</option>
                        @foreach ($this->accounts->where('status', 'active') as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('toAccountId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <x-input label="Monto" type="number" step="0.01" min="0"
                         wire:model.live.debounce.500ms="transferAmount" inputmode="decimal"
                         :error="$errors->first('transferAmount')" />

                {{-- Con monedas distintas se muestra cuanto llega, para que
                     nadie tenga que sacar la calculadora. --}}
                @if ($this->transferPreview && $this->transferPreview['cross'])
                    <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 text-sm">
                        <p class="text-slate-600">Se recibiran</p>
                        <p class="text-xl font-bold tabular-nums mt-0.5">
                            {{ $this->transferPreview['symbol'] }}{{ number_format($this->transferPreview['amount'], 2) }}
                        </p>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Tipo de cambio {{ rtrim(rtrim(number_format($this->transferPreview['rate'], 6), '0'), '.') }}
                        </p>
                    </div>
                @endif

                <x-input label="Concepto" wire:model="transferDescription" placeholder="Opcional" />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showTransfer', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Trasladar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
