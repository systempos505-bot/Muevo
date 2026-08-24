@php
    $symbol = $currency?->symbol ?? '$';
    $types = \App\Livewire\Promotions\Index::TYPES;
    $days = \App\Livewire\Promotions\Index::WEEKDAYS;
@endphp

<div>
    <x-page-header title="Promociones"
                   :subtitle="$this->runningNow . ' corriendo en este momento'">
        <x-slot:actions>
            @can('promotions.manage')
                <x-button size="sm" wire:click="create">+ Promocion</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar promocion por nombre"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">

        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
            <option value="all">Todas</option>
            <option value="running">Vigentes</option>
            <option value="active">Encendidas</option>
            <option value="inactive">Apagadas</option>
            <option value="expired">Vencidas</option>
        </select>
    </div>

    @if ($promotions->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">✽</p>
            <p class="mt-3 font-medium text-slate-900">Todavia no hay promociones</p>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">
                Un 2x1, un porcentaje los martes o un precio de paquete. El precio del
                producto no se toca: la promocion se ve como ahorro en el ticket.
            </p>
            @can('promotions.manage')
                <div class="mt-5">
                    <x-button wire:click="create">Crear la primera</x-button>
                </div>
            @endcan
        </x-card>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            @foreach ($promotions as $promotion)
                <x-card wire:key="promo-{{ $promotion->id }}"
                    @class(['opacity-60' => $promotion->status !== 'active' || $promotion->hasExpired()])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded-lg bg-indigo-100 text-indigo-700 text-sm font-bold tabular-nums">
                                    {{ $promotion->badge() }}
                                </span>
                                <p class="font-medium text-slate-900 truncate">{{ $promotion->name }}</p>
                            </div>

                            @if ($promotion->description)
                                <p class="text-sm text-slate-500 mt-1">{{ $promotion->description }}</p>
                            @endif

                            <p class="text-xs text-slate-500 mt-2">
                                @if ($promotion->applies_to_all)
                                    Todo el catalogo
                                @else
                                    {{ $promotion->targets->count() }} producto(s), categoria(s) o marca(s)
                                @endif

                                @if ($promotion->branch)
                                    · solo {{ $promotion->branch->name }}
                                @endif
                                @if ($promotion->priceList)
                                    · lista {{ $promotion->priceList->name }}
                                @endif
                                @if ($promotion->customerType)
                                    · clientes {{ $promotion->customerType->name }}
                                @endif
                            </p>

                            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                @if ($promotion->hasExpired())
                                    <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-700 text-xs">vencida</span>
                                @elseif ($promotion->status !== 'active')
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">apagada</span>
                                @elseif ($promotion->runsAt())
                                    <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-xs">corriendo ahora</span>
                                @else
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">fuera de horario</span>
                                @endif

                                @if ($promotion->starts_on || $promotion->ends_on)
                                    <span class="text-xs text-slate-500">
                                        {{ $promotion->starts_on?->format('d/m/Y') ?? '…' }}
                                        al {{ $promotion->ends_on?->format('d/m/Y') ?? '…' }}
                                    </span>
                                @endif

                                @if (is_array($promotion->weekdays) && $promotion->weekdays !== [])
                                    <span class="text-xs text-slate-500">
                                        {{ collect($promotion->weekdays)->map(fn ($d) => $days[(int) $d] ?? '')->join(', ') }}
                                    </span>
                                @endif

                                @if ($promotion->starts_at && $promotion->ends_at)
                                    <span class="text-xs text-slate-500">
                                        {{ substr((string) $promotion->starts_at, 0, 5) }}–{{ substr((string) $promotion->ends_at, 0, 5) }}
                                    </span>
                                @endif

                                @if ($promotion->combinable)
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs">combinable</span>
                                @endif

                                @if ($promotion->times_used > 0)
                                    <span class="text-xs text-slate-500">
                                        usada {{ $promotion->times_used }} vez(ces)
                                    </span>
                                @endif
                            </div>
                        </div>

                        @can('promotions.manage')
                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <x-button variant="ghost" size="sm" wire:click="edit('{{ $promotion->id }}')">
                                    Editar
                                </x-button>
                                <x-button variant="ghost" size="sm" wire:click="toggle('{{ $promotion->id }}')">
                                    {{ $promotion->status === 'active' ? 'Apagar' : 'Encender' }}
                                </x-button>
                                <button type="button" wire:click="delete('{{ $promotion->id }}')"
                                        class="text-xs text-slate-400 hover:text-rose-600 px-3">
                                    Eliminar
                                </button>
                            </div>
                        @endcan
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-4">{{ $promotions->links() }}</div>
    @endif

    {{-- ==================== Formulario ==================== --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar promocion' : 'Nueva promocion'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name"
                         placeholder="Martes de 2x1"
                         hint="Es el nombre que sale en el ticket del cliente"
                         :error="$errors->first('name')" />

                <x-input label="Descripcion (opcional)" wire:model="description"
                         placeholder="Solo en refrescos de litro"
                         :error="$errors->first('description')" />

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tipo</label>
                    <select wire:model.live="type"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Elegido el tipo, solo se piden los datos de ese tipo:
                     un 2x1 no tiene por que preguntar por un porcentaje. --}}
                @if ($type === 'nxm')
                    <div class="grid grid-cols-2 gap-3">
                        <x-input label="Se lleva" type="number" min="1" wire:model="buyQuantity"
                                 :error="$errors->first('buyQuantity')" />
                        <x-input label="Se regala" type="number" min="1" wire:model="getQuantity"
                                 hint="2 y 1 es un 2x1" :error="$errors->first('getQuantity')" />
                    </div>
                @elseif ($type === 'percent')
                    <x-input label="Porcentaje de descuento" type="number" step="0.01" min="0.01" max="100"
                             wire:model="discountPercent" :error="$errors->first('discountPercent')" />
                @elseif ($type === 'amount')
                    <x-input label="Descuento por unidad ({{ $symbol }})" type="number" step="0.01" min="0.01"
                             wire:model="discountAmount" :error="$errors->first('discountAmount')" />
                @else
                    <div class="grid grid-cols-2 gap-3">
                        <x-input label="Unidades del paquete" type="number" min="1" wire:model="buyQuantity"
                                 :error="$errors->first('buyQuantity')" />
                        <x-input label="Precio del paquete ({{ $symbol }})" type="number" step="0.01" min="0.01"
                                 wire:model="bundlePrice" :error="$errors->first('bundlePrice')" />
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    @if (in_array($type, ['percent', 'amount'], true))
                        <x-input label="Minimo de unidades" type="number" step="0.001" min="0"
                                 wire:model="minQuantity" :error="$errors->first('minQuantity')" />
                    @endif

                    <x-input label="Veces por linea (opcional)" type="number" min="1"
                             wire:model="maxUsesPerLine"
                             hint="En blanco, sin limite"
                             :error="$errors->first('maxUsesPerLine')" />
                </div>

                {{-- A que aplica --}}
                <div class="space-y-2 pt-2 border-t border-slate-100">
                    <label class="block text-sm font-medium text-slate-700">A que aplica</label>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model.live="appliesToAll"
                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Todo el catalogo
                    </label>

                    @unless ($appliesToAll)
                        <div class="flex gap-2">
                            <select wire:model.live="targetType"
                                    class="rounded-lg border border-slate-300 px-2 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                                <option value="product">Producto</option>
                                <option value="category">Categoria</option>
                                <option value="brand">Marca</option>
                            </select>
                            <input type="search" wire:model.live.debounce.300ms="targetSearch"
                                   placeholder="Buscar y agregar"
                                   class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm
                                          focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        @if ($this->targetResults->isNotEmpty())
                            <ul class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-40 overflow-y-auto">
                                @foreach ($this->targetResults as $row)
                                    <li>
                                        <button type="button"
                                                wire:click="addTarget('{{ $row['id'] }}', @js($row['name']))"
                                                class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
                                            {{ $row['name'] }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($targets !== [])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($targets as $index => $target)
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-slate-100 text-sm text-slate-700">
                                        {{ $target['name'] }}
                                        <button type="button" wire:click="removeTarget({{ $index }})"
                                                class="text-slate-400 hover:text-rose-600 leading-none">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @error('targets')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    @endunless
                </div>

                {{-- Vigencia --}}
                <div class="space-y-3 pt-2 border-t border-slate-100">
                    <label class="block text-sm font-medium text-slate-700">
                        Cuando corre
                        <span class="font-normal text-slate-500">— en blanco, siempre</span>
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <x-input label="Desde" type="date" wire:model="startsOn"
                                 :error="$errors->first('startsOn')" />
                        <x-input label="Hasta" type="date" wire:model="endsOn"
                                 :error="$errors->first('endsOn')" />
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($days as $number => $label)
                            <label @class([
                                'px-3 py-1.5 rounded-lg border text-sm cursor-pointer transition',
                                'bg-indigo-600 border-indigo-600 text-white' => in_array($number, $weekdays),
                                'border-slate-300 text-slate-600 hover:bg-slate-50' => ! in_array($number, $weekdays),
                            ])>
                                <input type="checkbox" value="{{ $number }}" wire:model.live="weekdays" class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <x-input label="Desde las" type="time" wire:model="startsAt"
                                 :error="$errors->first('startsAt')" />
                        <x-input label="Hasta las" type="time" wire:model="endsAt"
                                 :error="$errors->first('endsAt')" />
                    </div>
                </div>

                {{-- Acotaciones --}}
                <div class="space-y-3 pt-2 border-t border-slate-100">
                    <label class="block text-sm font-medium text-slate-700">
                        Solo para
                        <span class="font-normal text-slate-500">— en blanco, para todos</span>
                    </label>

                    <select wire:model="branchId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">Todas las sucursales</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model="priceListId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">Todas las listas de precios</option>
                        @foreach ($priceLists as $list)
                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model="customerTypeId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">Todos los clientes</option>
                        @foreach ($customerTypes as $customerType)
                            <option value="{{ $customerType->id }}">{{ $customerType->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                    <x-input label="Prioridad" type="number" min="0" wire:model="priority"
                             hint="Gana la mas alta" :error="$errors->first('priority')" />

                    <label class="flex items-end gap-2 text-sm text-slate-700 pb-3">
                        <input type="checkbox" wire:model="combinable"
                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Se puede sumar a otras
                    </label>
                </div>

                <div class="flex gap-2 pt-2">
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                    <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">
                        Cancelar
                    </x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
