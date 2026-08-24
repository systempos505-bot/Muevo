<div>
    <x-page-header :title="$productId ? 'Editar producto' : 'Nuevo producto'"
                   :subtitle="$name ?: 'Sin nombre'">
        <x-slot:actions>
            <a href="{{ route('products') }}" wire:navigate>
                <x-button variant="secondary" size="sm">Cancelar</x-button>
            </a>
            <x-button size="sm" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Guardar</span>
                <span wire:loading wire:target="save">Guardando...</span>
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $tabs = [
            'general' => 'General',
            'prices' => 'Precios',
            'units' => 'Presentaciones',
            'inventory' => 'Inventario',
        ];
    @endphp

    {{-- Pestanas: crear un producto simple es llenar solo la primera. --}}
    <div class="flex gap-1 mb-4 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition',
                    'border-indigo-600 text-indigo-600' => $tab === $key,
                    'border-transparent text-slate-500 hover:text-slate-800' => $tab !== $key,
                ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3">
            <p class="text-sm font-medium text-rose-800">Revisa estos puntos:</p>
            <ul class="mt-1 text-sm text-rose-700 list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="save" class="space-y-4">

        {{-- ================= GENERAL ================= --}}
        <div @class(['hidden' => $tab !== 'general'])>
            <x-card>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="lg:col-span-2">
                        <x-input label="Nombre del producto" wire:model.blur="name"
                                 placeholder="Acetaminofen 500mg caja x 20"
                                 :error="$errors->first('name')" autofocus />
                    </div>

                    <x-input label="SKU" wire:model.blur="sku" placeholder="MED-0001"
                             hint="Codigo interno unico de tu negocio"
                             :error="$errors->first('sku')" />

                    <x-input label="Codigo de barra" wire:model.blur="barcode"
                             placeholder="7501234567890"
                             hint="El que trae el producto de fabrica"
                             :error="$errors->first('barcode')" />

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Categoria</label>
                        <select wire:model="categoryId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin categoria</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Marca</label>
                        <select wire:model="brandId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Sin marca</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
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

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Unidad base</label>
                        <select wire:model="baseUnitId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            @foreach ($this->units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endforeach
                        </select>
                        {{-- Todo el inventario se guarda en esta unidad. --}}
                        <p class="text-xs text-slate-500">En esta unidad se guarda el inventario</p>
                    </div>

                    <div class="lg:col-span-2 space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Descripcion</label>
                        <textarea wire:model.blur="description" rows="2"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Opcional"></textarea>
                    </div>
                </div>
            </x-card>
        </div>

        {{-- ================= PRECIOS ================= --}}
        <div @class(['hidden' => $tab !== 'prices']) class="space-y-4">
            <x-card title="Costo e impuesto">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input label="Precio de compra (sin impuesto)" type="number" step="0.0001" min="0"
                             wire:model.live.debounce.400ms="cost" inputmode="decimal"
                             :error="$errors->first('cost')" />

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Impuesto</label>
                        <select wire:model.live="taxId"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Exento</option>
                            @foreach ($taxes as $tax)
                                <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    @if (auth()->user()->tenant->prices_include_tax)
                        Los precios de abajo <strong>ya incluyen el impuesto</strong>.
                    @else
                        Los precios de abajo son <strong>sin impuesto</strong>; se agrega al cobrar.
                    @endif
                </p>
            </x-card>

            <x-card title="Precios de venta"
                    description="Un precio por lista. Escribe el precio o el margen que quieres ganar.">
                <div class="space-y-3">
                    @foreach ($priceRows as $index => $row)
                        <div class="grid grid-cols-12 gap-3 items-end">
                            <div class="col-span-12 sm:col-span-4">
                                <p class="text-sm font-medium text-slate-700">{{ $row['name'] }}</p>
                            </div>

                            <div class="col-span-6 sm:col-span-4">
                                <label class="block text-xs text-slate-500 mb-1">Precio</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">
                                        {{ $currency?->symbol }}
                                    </span>
                                    <input type="number" step="0.01" min="0" inputmode="decimal"
                                           wire:model.live.debounce.500ms="priceRows.{{ $index }}.price"
                                           class="w-full rounded-lg border border-slate-300 pl-7 pr-3 py-2.5
                                                  text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>

                            <div class="col-span-6 sm:col-span-4">
                                <label class="block text-xs text-slate-500 mb-1">Margen</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 px-3 py-2.5 rounded-lg bg-slate-50 text-right tabular-nums text-sm">
                                        @if ($row['margin'] === null)
                                            <span class="text-slate-400">—</span>
                                        @else
                                            <span @class([
                                                'font-medium',
                                                'text-rose-600' => $row['margin'] < 0,
                                                'text-emerald-700' => $row['margin'] >= 0,
                                            ])>{{ number_format($row['margin'], 1) }}%</span>
                                        @endif
                                    </div>
                                    {{-- Atajos de margen: lo mas rapido para fijar precio. --}}
                                    @foreach ([20, 30, 40] as $preset)
                                        <button type="button"
                                                wire:click="applyMargin({{ $index }}, {{ $preset }})"
                                                class="px-2 py-1.5 text-xs rounded-md border border-slate-300
                                                       text-slate-600 hover:bg-slate-50">
                                            {{ $preset }}%
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($cost <= 0)
                    <p class="mt-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        Captura el precio de compra para poder ver el margen y usar los atajos.
                    </p>
                @endif
            </x-card>
        </div>

        {{-- ================= PRESENTACIONES ================= --}}
        <div @class(['hidden' => $tab !== 'units'])>
            <x-card title="Formas de vender este producto"
                    description="Por unidad, por docena, por caja. La equivalencia dice cuantas unidades base trae cada una.">
                <div class="space-y-3">
                    @foreach ($unitRows as $index => $row)
                        <div class="grid grid-cols-12 gap-3 items-end p-3 rounded-lg border border-slate-200">
                            <div class="col-span-12 sm:col-span-4 space-y-1.5">
                                <label class="block text-xs text-slate-500">Unidad</label>
                                <select wire:model="unitRows.{{ $index }}.unit_id"
                                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                                    <option value="">Elegir...</option>
                                    @foreach ($this->units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-span-6 sm:col-span-2 space-y-1.5">
                                <label class="block text-xs text-slate-500">Equivale a</label>
                                <input type="number" step="0.0001" min="0.0001" inputmode="decimal"
                                       wire:model="unitRows.{{ $index }}.factor"
                                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5
                                              text-right tabular-nums focus:ring-2 focus:ring-indigo-500">
                            </div>

                            <div class="col-span-6 sm:col-span-3 space-y-1.5">
                                <label class="block text-xs text-slate-500">Codigo de barra</label>
                                <input type="text" wire:model="unitRows.{{ $index }}.barcode"
                                       placeholder="Opcional"
                                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                            </div>

                            <div class="col-span-12 sm:col-span-3 flex items-center gap-3">
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="radio" name="defaultUnit"
                                           wire:click="setDefaultUnit({{ $index }})"
                                           @checked($row['is_default'])
                                           class="text-indigo-600 focus:ring-indigo-500">
                                    Principal
                                </label>

                                @if (count($unitRows) > 1)
                                    <button type="button" wire:click="removeUnitRow({{ $index }})"
                                            class="ml-auto text-sm text-rose-600 hover:text-rose-700">
                                        Quitar
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <x-button type="button" variant="secondary" size="sm" wire:click="addUnitRow">
                        + Agregar presentacion
                    </x-button>
                </div>
            </x-card>
        </div>

        {{-- ================= INVENTARIO ================= --}}
        <div @class(['hidden' => $tab !== 'inventory']) class="space-y-4">
            <x-card title="Control de existencia">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="trackStock"
                           class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Este producto maneja stock</span>
                        <span class="block text-xs text-slate-500">
                            Desactivalo para servicios, que no se descuentan del inventario.
                        </span>
                    </span>
                </label>

                @if ($trackStock)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5">
                        @if (! $productId)
                            <x-input label="Inventario inicial" type="number" step="0.001" min="0"
                                     wire:model="initialStock" inputmode="decimal"
                                     hint="Lo que tienes hoy"
                                     :error="$errors->first('initialStock')" />
                        @endif

                        <x-input label="Stock minimo" type="number" step="0.001" min="0"
                                 wire:model="minStock" inputmode="decimal"
                                 hint="Avisa al llegar aqui. 0 = sin aviso"
                                 :error="$errors->first('minStock')" />

                        <x-input label="Stock maximo" type="number" step="0.001" min="0"
                                 wire:model="maxStock" inputmode="decimal"
                                 hint="Opcional"
                                 :error="$errors->first('maxStock')" />
                    </div>
                @endif
            </x-card>

            @if ($trackStock)
                <x-card title="Control avanzado"
                        description="Para productos que vencen o que se rastrean pieza por pieza.">
                    <div class="space-y-4">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="trackLots"
                                   class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">Manejar lotes</span>
                                <span class="block text-xs text-slate-500">
                                    Agrupa la mercancia por numero de lote.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="trackExpiry"
                                   class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">Fecha de vencimiento</span>
                                <span class="block text-xs text-slate-500">
                                    Sale primero lo que vence antes. Activa lotes automaticamente.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="trackSerials"
                                   class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">Numeros de serie</span>
                                <span class="block text-xs text-slate-500">
                                    Cada pieza se registra individualmente.
                                </span>
                            </span>
                        </label>

                        @if ($trackExpiry)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-100">
                                <x-input label="Avisar dias antes" type="number" min="0" max="3650"
                                         wire:model="expiryAlertDays" inputmode="numeric"
                                         hint="Aparece en las alertas del panel"
                                         :error="$errors->first('expiryAlertDays')" />

                                <x-input label="Bloquear venta dias antes" type="number" min="0" max="3650"
                                         wire:model="expiryBlockDays" inputmode="numeric"
                                         hint="0 = nunca bloquear"
                                         :error="$errors->first('expiryBlockDays')" />
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif
        </div>

        {{-- Guardar fijo abajo en celular: no hay que subir hasta la barra. --}}
        <div class="lg:hidden fixed bottom-16 inset-x-0 p-3 bg-white border-t border-slate-200 z-20">
            <x-button type="submit" size="lg" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Guardar producto</span>
                <span wire:loading wire:target="save">Guardando...</span>
            </x-button>
        </div>
    </form>
</div>
