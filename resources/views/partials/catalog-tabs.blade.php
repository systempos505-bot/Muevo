@php
$tabs = [
    ['label' => 'Categorias', 'route' => 'catalog.categories'],
    ['label' => 'Marcas', 'route' => 'catalog.brands'],
    ['label' => 'Unidades', 'route' => 'catalog.units'],
    ['label' => 'Listas de precios', 'route' => 'catalog.price-lists'],
];
@endphp

<div class="flex gap-1 mb-4 overflow-x-auto border-b border-slate-200">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" wire:navigate
            @class([
                'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition',
                'border-indigo-600 text-indigo-600' => request()->routeIs($tab['route']),
                'border-transparent text-slate-500 hover:text-slate-800' => ! request()->routeIs($tab['route']),
            ])>
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
