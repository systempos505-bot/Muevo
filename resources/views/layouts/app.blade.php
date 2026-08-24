<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>{{ $title ?? 'Muevo POS' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">

<div class="min-h-full lg:flex">

    {{-- Menu lateral: solo en pantallas grandes --}}
    <aside class="hidden lg:flex lg:w-60 lg:flex-col lg:fixed lg:inset-y-0 bg-slate-900 text-slate-300">
        <div class="flex items-center gap-2 px-5 h-16 border-b border-slate-800">
            <span class="grid place-items-center w-8 h-8 rounded-lg bg-indigo-500 text-white font-bold">M</span>
            <span class="font-semibold text-white">Muevo</span>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            @foreach (\App\Support\Navigation::items() as $item)
                <a href="{{ $item['url'] }}" wire:navigate
                   @class([
                       'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition',
                       'bg-indigo-500 text-white' => $item['active'],
                       'hover:bg-slate-800 hover:text-white' => ! $item['active'],
                   ])>
                    <span class="text-lg leading-none">{{ $item['icon'] }}</span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="border-t border-slate-800 p-4">
            <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
            <p class="text-xs text-slate-400 truncate">{{ auth()->user()->tenant->name }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button class="text-xs text-slate-400 hover:text-white transition">Cerrar sesion</button>
            </form>
        </div>
    </aside>

    <div class="flex-1 lg:pl-60 flex flex-col min-h-screen">

        {{-- Barra superior: solo en celular, donde no hay menu lateral --}}
        <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-slate-200">
            <div class="flex items-center justify-between h-14 px-4">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="grid place-items-center w-7 h-7 rounded-lg bg-indigo-500 text-white text-sm font-bold">M</span>
                    <span class="font-medium text-slate-900 truncate">
                        {{ auth()->user()->tenant->name }}
                    </span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-xs text-slate-500 hover:text-slate-900 transition">Salir</button>
                </form>
            </div>
        </header>

        {{-- pb-24 deja aire para que la barra inferior no tape el contenido --}}
        <main class="flex-1 p-4 sm:p-6 pb-24 lg:pb-6">
            {{ $slot }}
        </main>
    </div>

    {{-- Barra inferior: solo en celular y tablet --}}
    @php
        $primaryItems = \App\Support\Navigation::items(primary: true);
        $allItems = \App\Support\Navigation::items();
        // Lo que no cabe abajo: si no se pudiera llegar aqui, en celular
        // habria pantallas del sistema simplemente inalcanzables.
        $moreItems = collect($allItems)
            ->whereNotIn('label', collect($primaryItems)->pluck('label'))
            ->values();
    @endphp

    <div x-data="{ more: false }" class="lg:hidden">
        {{-- Menu de lo que no cabe en la barra --}}
        <div x-show="more" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-40 bg-slate-900/40" x-on:click="more = false"></div>

        <div x-show="more" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="fixed bottom-0 inset-x-0 z-50 bg-white rounded-t-2xl border-t border-slate-200
                    pb-[calc(env(safe-area-inset-bottom)+1rem)] max-h-[75vh] overflow-y-auto">
            <div class="flex justify-center py-3">
                <span class="w-10 h-1 rounded-full bg-slate-300"></span>
            </div>
            <div class="grid grid-cols-3 gap-1 px-3 pb-2">
                @foreach ($moreItems as $item)
                    <a href="{{ $item['url'] }}" wire:navigate x-on:click="more = false"
                       @class([
                           'flex flex-col items-center gap-1 py-4 rounded-xl text-xs font-medium transition',
                           'bg-indigo-50 text-indigo-600' => $item['active'],
                           'text-slate-600 hover:bg-slate-50' => ! $item['active'],
                       ])>
                        <span class="text-2xl leading-none">{{ $item['icon'] }}</span>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        <nav class="fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200
                    pb-[env(safe-area-inset-bottom)]">
            {{-- Reparto flexible: los modulos aun no construidos no dejan
                 huecos ni empujan los botones hacia un lado. --}}
            <div class="flex">
                @foreach ($primaryItems as $item)
                    <a href="{{ $item['url'] }}" wire:navigate
                       @class([
                           'flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium transition',
                           'text-indigo-600' => $item['active'],
                           'text-slate-500' => ! $item['active'],
                       ])>
                        <span class="text-xl leading-none">{{ $item['icon'] }}</span>
                        {{ $item['label'] }}
                    </a>
                @endforeach

                @if ($moreItems->isNotEmpty())
                    <button type="button" x-on:click="more = ! more"
                            @class([
                                'flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium transition',
                                'text-indigo-600' => collect($moreItems)->contains('active', true),
                                'text-slate-500' => ! collect($moreItems)->contains('active', true),
                            ])>
                        <span class="text-xl leading-none">⋯</span>
                        Mas
                    </button>
                @endif
            </div>
        </nav>
    </div>
</div>

{{-- Avisos breves de exito o error --}}
<div x-data="{ show: false, message: '', kind: 'success' }"
     x-on:notify.window="message = $event.detail.message; kind = $event.detail.kind ?? 'success'; show = true; setTimeout(() => show = false, 3500)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2"
     class="fixed bottom-24 lg:bottom-6 right-4 left-4 sm:left-auto sm:w-96 z-50">
    <div :class="kind === 'error' ? 'bg-rose-600' : 'bg-slate-900'"
         class="text-white px-4 py-3 rounded-xl shadow-lg text-sm" x-text="message"></div>
</div>

</body>
</html>
