<div>
    <x-slot:header>Productos</x-slot:header>
    <x-slot:subheader>{{ $products->total() }} en el catalogo</x-slot:subheader>

    <x-slot:actions>
        @if (auth()->user()->can('products.create'))
            <a href="{{ route('products.create') }}" wire:navigate>
                <x-button>+ Nuevo</x-button>
            </a>
        @endif
    </x-slot:actions>

    {{-- Filtros. En celular se apilan; en escritorio van en una fila. --}}
    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <div class="relative flex-1">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por nombre, SKU o codigo de barra"
                class="w-full rounded-lg border border-slate-300 pl-10 pr-3 py-2.5 text-slate-900
                       placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</span>
        </div>

        <select wire:model.live="categoryId"
                class="rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 focus:ring-2 focus:ring-indigo-500">
            <option value="">Todas las categorias</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->fullName() }}</option>
            @endforeach
        </select>

        <select wire:model.live="stockFilter"
                class="rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 focus:ring-2 focus:ring-indigo-500">
            <option value="all">Todo el stock</option>
            <option value="low">Stock bajo</option>
            <option value="out">Agotados</option>
        </select>

        <select wire:model.live="status"
                class="rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 focus:ring-2 focus:ring-indigo-500">
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
            <option value="all">Todos</option>
        </select>
    </div>

    @if ($products->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">▤</p>
            <p class="mt-3 font-medium text-slate-900">
                {{ $search || $categoryId ? 'Sin resultados' : 'Aun no tienes productos' }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                {{ $search || $categoryId
                    ? 'Prueba con otro termino o quita los filtros.'
                    : 'Crea el primero para empezar a vender.' }}
            </p>
            <div class="mt-5">
                @if ($search || $categoryId)
                    <x-button variant="secondary" wire:click="clearFilters">Quitar filtros</x-button>
                @elseif (auth()->user()->can('products.create'))
                    <a href="{{ route('products.create') }}" wire:navigate>
                        <x-button>Crear producto</x-button>
                    </a>
                @endif
            </div>
        </x-card>
    @else

        {{-- Tarjetas: celular y tablet --}}
        <div class="space-y-2 lg:hidden">
            @foreach ($products as $product)
                <a href="{{ route('products.edit', $product) }}" wire:navigate
                   class="block bg-white rounded-xl border border-slate-200 p-4 active:bg-slate-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900 truncate">{{ $product->name }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $product->sku }}
                                @if ($product->category) · {{ $product->category->name }} @endif
                            </p>
                        </div>
                        <p class="font-semibold text-slate-900 shrink-0">
                            {{ $currency?->symbol }}{{ number_format($product->default_price ?? 0, 2) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 mt-3 text-xs">
                        @if ($product->track_stock)
                            <span @class([
                                'px-2 py-0.5 rounded-full font-medium',
                                'bg-rose-100 text-rose-700' => ($product->stock ?? 0) <= 0,
                                'bg-amber-100 text-amber-700' => ($product->stock ?? 0) > 0
                                    && $product->min_stock > 0 && ($product->stock ?? 0) <= $product->min_stock,
                                'bg-emerald-100 text-emerald-700' => ($product->stock ?? 0) > 0
                                    && ($product->min_stock <= 0 || ($product->stock ?? 0) > $product->min_stock),
                            ])>
                                {{ rtrim(rtrim(number_format($product->stock ?? 0, 2), '0'), '.') }}
                                {{ $product->baseUnit?->code }}
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">
                                Servicio
                            </span>
                        @endif

                        @if ($product->variants_count > 0)
                            <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 font-medium">
                                {{ $product->variants_count }} variantes
                            </span>
                        @endif

                        @if ($product->track_expiry)
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">
                                Vencimiento
                            </span>
                        @endif

                        @if ($product->status !== 'active')
                            <span class="px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 font-medium">
                                Inactivo
                            </span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Tabla: escritorio --}}
        <x-card flush class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-5 py-3 font-medium">Producto</th>
                            <th class="px-5 py-3 font-medium">Categoria</th>
                            <th class="px-5 py-3 font-medium text-right">Costo</th>
                            <th class="px-5 py-3 font-medium text-right">Precio</th>
                            <th class="px-5 py-3 font-medium text-right">Margen</th>
                            <th class="px-5 py-3 font-medium text-right">Stock</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            @php
                                $price = (float) ($product->default_price ?? 0);
                                $margin = $price > 0 ? $product->marginFor($price, auth()->user()->tenant->taxMode()) : null;
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('products.edit', $product) }}" wire:navigate
                                       class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $product->name }}
                                    </a>
                                    <p class="text-xs text-slate-500">
                                        {{ $product->sku }}
                                        @if ($product->status !== 'active')
                                            · <span class="text-slate-400">inactivo</span>
                                        @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $product->category?->name ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600 tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($product->cost, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">
                                    {{ $currency?->symbol }}{{ number_format($price, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if ($margin === null)
                                        <span class="text-slate-400">—</span>
                                    @else
                                        <span @class([
                                            'font-medium',
                                            'text-rose-600' => $margin < 0,
                                            'text-slate-600' => $margin >= 0,
                                        ])>{{ number_format($margin, 1) }}%</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if (! $product->track_stock)
                                        <span class="text-slate-400">—</span>
                                    @else
                                        <span @class([
                                            'font-medium',
                                            'text-rose-600' => ($product->stock ?? 0) <= 0,
                                            'text-amber-600' => ($product->stock ?? 0) > 0
                                                && $product->min_stock > 0 && ($product->stock ?? 0) <= $product->min_stock,
                                            'text-slate-700' => ($product->stock ?? 0) > 0
                                                && ($product->min_stock <= 0 || ($product->stock ?? 0) > $product->min_stock),
                                        ])>
                                            {{ rtrim(rtrim(number_format($product->stock ?? 0, 2), '0'), '.') }}
                                        </span>
                                        <span class="text-xs text-slate-400">{{ $product->baseUnit?->code }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if (auth()->user()->can('products.edit'))
                                        <button wire:click="toggleStatus('{{ $product->id }}')"
                                                class="text-xs text-slate-500 hover:text-slate-900">
                                            {{ $product->status === 'active' ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    @endif
</div>
