<div>
    <x-page-header title="Catalogo" subtitle="Marcas">
        <x-slot:actions>
            @can('products.edit')
                <x-button size="sm" wire:click="create">+ Marca</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.catalog-tabs')

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar marca"
               class="w-full sm:max-w-xs rounded-lg border border-slate-300 px-3 py-2.5
                      placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">
    </div>

    @if ($brands->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">◈</p>
            <p class="mt-3 font-medium text-slate-900">
                {{ $search ? 'Sin resultados' : 'Aun no tienes marcas' }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                {{ $search ? 'Prueba con otro nombre.' : 'Son opcionales, pero ayudan a filtrar el catalogo.' }}
            </p>
        </x-card>
    @else
        <x-card flush>
            <ul class="divide-y divide-slate-100">
                @foreach ($brands as $brand)
                    <li class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900 truncate">{{ $brand->name }}</p>
                            <p class="text-xs text-slate-500">{{ $brand->products_count }} producto(s)</p>
                        </div>

                        @can('products.edit')
                            <div class="flex items-center gap-1 shrink-0">
                                <button wire:click="edit('{{ $brand->id }}')"
                                        class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                    Editar
                                </button>
                                <button wire:click="delete('{{ $brand->id }}')"
                                        wire:confirm="Eliminar la marca {{ $brand->name }}?"
                                        class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                    Borrar
                                </button>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar marca' : 'Nueva marca'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Bayer"
                         :error="$errors->first('name')" autofocus />

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
