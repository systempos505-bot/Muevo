<div>
    <x-page-header title="Catalogo" subtitle="Categorias y subcategorias">
        <x-slot:actions>
            @can('products.edit')
                <x-button size="sm" wire:click="create">+ Categoria</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('partials.catalog-tabs')

    @if ($roots->isEmpty())
        <x-card class="text-center py-16">
            <p class="text-4xl">☰</p>
            <p class="mt-3 font-medium text-slate-900">Aun no tienes categorias</p>
            <p class="mt-1 text-sm text-slate-500">Sirven para ordenar el catalogo y filtrar en la caja.</p>
            @can('products.edit')
                <div class="mt-5">
                    <x-button wire:click="create">Crear la primera</x-button>
                </div>
            @endcan
        </x-card>
    @else
        <div class="space-y-2">
            @foreach ($roots as $category)
                <x-card flush>
                    <div class="flex items-center gap-3 px-5 py-3.5">
                        @if ($category->color)
                            <span class="w-3 h-3 rounded-full shrink-0"
                                  style="background-color: {{ $category->color }}"></span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p @class([
                                'font-medium truncate',
                                'text-slate-900' => $category->status === 'active',
                                'text-slate-400 line-through' => $category->status !== 'active',
                            ])>{{ $category->name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $category->products_count }} producto(s)
                                @if ($category->children->isNotEmpty())
                                    · {{ $category->children->count() }} subcategoria(s)
                                @endif
                            </p>
                        </div>

                        @can('products.edit')
                            <div class="flex items-center gap-1 shrink-0">
                                <button wire:click="create('{{ $category->id }}')"
                                        class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded">
                                    + Sub
                                </button>
                                <button wire:click="edit('{{ $category->id }}')"
                                        class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                    Editar
                                </button>
                                <button wire:click="toggleStatus('{{ $category->id }}')"
                                        class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                    {{ $category->status === 'active' ? 'Ocultar' : 'Activar' }}
                                </button>
                                <button wire:click="delete('{{ $category->id }}')"
                                        wire:confirm="Eliminar la categoria {{ $category->name }}?"
                                        class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                    Borrar
                                </button>
                            </div>
                        @endcan
                    </div>

                    @if ($category->children->isNotEmpty())
                        <ul class="border-t border-slate-100 bg-slate-50/60">
                            @foreach ($category->children as $child)
                                <li class="flex items-center gap-3 pl-10 pr-5 py-2.5 border-b border-slate-100 last:border-0">
                                    <div class="min-w-0 flex-1">
                                        <p @class([
                                            'text-sm truncate',
                                            'text-slate-700' => $child->status === 'active',
                                            'text-slate-400 line-through' => $child->status !== 'active',
                                        ])>{{ $child->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $child->products_count }} producto(s)</p>
                                    </div>

                                    @can('products.edit')
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button wire:click="edit('{{ $child->id }}')"
                                                    class="px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 rounded">
                                                Editar
                                            </button>
                                            <button wire:click="delete('{{ $child->id }}')"
                                                    wire:confirm="Eliminar {{ $child->name }}?"
                                                    class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded">
                                                Borrar
                                            </button>
                                        </div>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            @endforeach
        </div>
    @endif

    @if ($showForm)
        <x-modal :title="$editingId ? 'Editar categoria' : 'Nueva categoria'" wire="showForm">
            <form wire:submit="save" class="space-y-4">
                <x-input label="Nombre" wire:model="name" placeholder="Medicamentos"
                         :error="$errors->first('name')" autofocus />

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Depende de</label>
                    <select wire:model="parentId"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Ninguna (categoria principal)</option>
                        @foreach ($parents as $parent)
                            @continue($parent->id === $editingId)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    @error('parentId')
                        <p class="text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Color</label>
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="color"
                               class="w-12 h-11 rounded-lg border border-slate-300 cursor-pointer">
                        <span class="text-xs text-slate-500">Ayuda a ubicarla rapido en la caja</span>
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <x-button type="button" variant="secondary" class="flex-1"
                              wire:click="$set('showForm', false)">Cancelar</x-button>
                    <x-button type="submit" class="flex-1">Guardar</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
