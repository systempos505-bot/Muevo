@props(['title', 'subtitle' => null])

{{--
    La cabecera de cada pantalla se dibuja DENTRO del componente Livewire.
    Si viviera en el layout, quedaria fuera del elemento raiz del
    componente y sus botones con wire:click no harian nada.
--}}
<div class="flex items-start justify-between gap-3 mb-5">
    <div class="min-w-0">
        <h1 class="text-xl font-semibold text-slate-900 truncate">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-sm text-slate-500 truncate mt-0.5">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
    @endisset
</div>
