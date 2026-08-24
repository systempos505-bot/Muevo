@props(['title' => null, 'wire' => null])

{{-- Hoja inferior en celular, dialogo centrado en escritorio. --}}
<div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/50" @if ($wire) wire:click="$set('{{ $wire }}', false)" @endif></div>

    <div class="relative w-full sm:max-w-lg bg-white rounded-t-2xl sm:rounded-2xl shadow-xl
                max-h-[90vh] overflow-y-auto">
        @if ($title)
            <header class="flex items-center justify-between px-5 py-4 border-b border-slate-100 sticky top-0 bg-white">
                <h2 class="font-semibold text-slate-900">{{ $title }}</h2>
                @if ($wire)
                    <button type="button" wire:click="$set('{{ $wire }}', false)"
                            class="text-slate-400 hover:text-slate-700 text-xl leading-none">&times;</button>
                @endif
            </header>
        @endif

        <div class="p-5">{{ $slot }}</div>
    </div>
</div>
