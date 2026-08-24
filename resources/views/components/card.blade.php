@props(['title' => null, 'description' => null, 'flush' => false])

<section {{ $attributes->class(['bg-white rounded-xl border border-slate-200 overflow-hidden']) }}>
    @if ($title)
        <header class="px-5 py-4 border-b border-slate-100">
            <h2 class="font-semibold text-slate-900">{{ $title }}</h2>
            @if ($description)
                <p class="text-sm text-slate-500 mt-0.5">{{ $description }}</p>
            @endif
        </header>
    @endif

    {{-- `flush` deja el contenido pegado al borde: lo usan las tablas,
         que traen su propio espaciado por celda. --}}
    <div @class(['p-5' => ! $flush])>
        {{ $slot }}
    </div>
</section>
