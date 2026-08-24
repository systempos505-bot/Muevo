<div>
    <x-page-header title="Gastos" :subtitle="$this->summary['items'] . ' gasto(s) en el periodo'">
        <x-slot:actions>
            @can('expenses.create')
                <x-button variant="secondary" size="sm" wire:click="$set('showCategories', true)">
                    Categorias
                </x-button>
                <x-button size="sm" wire:click="create">+ Gasto</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
        <x-card>
            <p class="text-sm text-slate-500">Total gastado</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-rose-600">
                {{ $currency?->symbol }}{{ number_format($this->summary['total'], 2) }}
            </p>
        </x-card>

        {{-- En que se va el dinero: es la pregunta que este modulo responde. --}}
        <x-card title="Por categoria" flush class="lg:col-span-2">
            @if ($this->byCategory === [])
                <p class="px-5 py-6 text-center text-sm text-slate-500">
                    Sin gastos en el periodo elegido.
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($this->byCategory as $row)
                        @php
                            $share = $this->summary['total'] > 0
                                ? round($row['total'] / $this->summary['total'] * 100)
                                : 0;
                        @endphp
                        <li class="px-5 py-2.5">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-slate-700">{{ $row['name'] }}</span>
                                <span class="font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($row['total'], 2) }}
                                    <span class="text-xs text-slate-400 ml-1">{{ $share }}%</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-indigo-500 rounded-full"
                                     style="width: {{ max($share, 2) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar por concepto, folio o referencia"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="categoryId"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todas las categorias</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>

        <input type="date" wire:model.live="from"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
        <input type="date" wire:model.live="to"
               class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="registered">Registrados</option>
            <option value="cancelled">Anulados</option>
            <option value="all">Todos</option>
        </select>
    </div>

    @if ($expenses->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">◇</p>
            <p class="mt-3 font-medium text-slate-900">Sin gastos en este periodo</p>
            <p class="mt-1 text-sm text-slate-500">
                Registra la renta, los servicios y todo lo que sale que no es mercancia.
            </p>
            <div class="mt-5 flex justify-center gap-2">
                <x-button variant="secondary" wire:click="clearFilters">Quitar filtros</x-button>
                @can('expenses.create')
                    <x-button wire:click="create">Registrar gasto</x-button>
                @endcan
            </div>
        </x-card>
    @else
        <x-card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Fecha</th>
                            <th class="px-5 py-3 font-medium">Concepto</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Categoria</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Cuenta</th>
                            <th class="px-5 py-3 font-medium text-right">Total</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($expenses as $expense)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ $expense->expense_date->format('d/m/Y') }}
                                    <p class="text-xs text-slate-400">{{ $expense->folio }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <p @class([
                                        'text-slate-900',
                                        'line-through text-slate-400' => $expense->isCancelled(),
                                    ])>{{ $expense->description }}</p>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        @if ($expense->supplier)
                                            <span class="text-xs text-slate-500">{{ $expense->supplier->name }}</span>
                                        @endif
                                        @if ($expense->is_recurring)
                                            <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">
                                                recurrente
                                            </span>
                                        @endif
                                        @if ($expense->isCancelled())
                                            <span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-xs">
                                                anulado
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden sm:table-cell">
                                    {{ $expense->category?->name ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600 hidden lg:table-cell">
                                    {{ $expense->account?->name ?? 'Sin cuenta' }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($expense->total, 2) }}
                                    @if ($expense->tax > 0)
                                        <p class="text-xs text-slate-400">
                                            imp. {{ number_format($expense->tax, 2) }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @unless ($expense->isCancelled())
                                        @can('expenses.create')
                                            @if ($expense->is_recurring)
                                                <button wire:click="repeat('{{ $expense->id }}')"
                                                        class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                                    Repetir
                                                </button>
                                            @endif
                                        @endcan
                                        @can('expenses.void')
                                            <button wire:click="openCancel('{{ $expense->id }}')"
                                                    class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                                Anular
                                            </button>
                                        @endcan
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $expenses->links() }}</div>
    @endif

    {{-- ================= MODALES ================= --}}

    @if ($showForm)
        <x-modal title="Registrar gasto" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="De que fue el gasto" wire:model="description"
                         placeholder="Renta del local de agosto"
                         :error="$errors->first('description')" autofocus />

                <div class="grid grid-cols-2 gap-3">
                    <x-input label="Monto total" type="number" step="0.01" min="0"
                             wire:model="total" inputmode="decimal"
                             :error="$errors->first('total')" />
                    <x-input label="Del cual, impuesto" type="number" step="0.01" min="0"
                             wire:model="tax" inputmode="decimal"
                             hint="0 si no lleva"
                             :error="$errors->first('tax')" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Categoria</label>
                        <select wire:model="formCategoryId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin categoria</option>
                            @foreach ($categories->where('status', 'active') as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-input label="Fecha" type="date" wire:model="expenseDate"
                             :error="$errors->first('expenseDate')" />
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Sale de</label>
                    <select wire:model="accountId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">No descontar de ninguna cuenta</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">
                                {{ $account->name }}
                                ({{ $account->currency?->symbol }}{{ number_format($account->balance, 2) }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500">
                        Si eliges cuenta, el monto baja de su saldo.
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Proveedor</label>
                    <select wire:model="supplierId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Sin proveedor</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-input label="Referencia" wire:model="reference"
                         placeholder="Numero de recibo, opcional"
                         :error="$errors->first('reference')" />

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model="isRecurring"
                           class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Se repite cada mes</span>
                        <span class="block text-xs text-slate-500">
                            Podras volver a registrarlo con un clic, sin recapturar nada.
                        </span>
                    </span>
                </label>

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Registrar gasto</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showCancel)
        <x-modal title="Anular gasto" wire="showCancel">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-slate-600">
                    El dinero vuelve a la cuenta de la que salio. El gasto no se borra:
                    queda marcado con su motivo.
                </p>

                <x-input label="Motivo" wire:model="cancelReason"
                         placeholder="Se registro dos veces por error"
                         :error="$errors->first('cancelReason')" autofocus />

                <div class="flex gap-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showCancel', false)">Cancelar</x-button>
                    <x-button type="submit" variant="danger" class="flex-1">Anular gasto</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($showCategories)
        <x-modal title="Categorias de gasto" wire="showCategories">
            <form wire:submit="addCategory" class="flex gap-2 mb-4">
                <div class="flex-1">
                    <input type="text" wire:model="newCategory" placeholder="Nueva categoria"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2.5
                                  focus:ring-2 focus:ring-indigo-500">
                    @error('newCategory')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <x-button type="submit">Agregar</x-button>
            </form>

            <ul class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                @foreach ($categories as $category)
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="text-sm text-slate-900">{{ $category->name }}</p>
                            <p class="text-xs text-slate-500">{{ $category->expenses_count }} gasto(s)</p>
                        </div>
                        <button type="button" wire:click="deleteCategory('{{ $category->id }}')"
                                wire:confirm="Eliminar {{ $category->name }}?"
                                class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded shrink-0">
                            Borrar
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-modal>
    @endif
</div>
