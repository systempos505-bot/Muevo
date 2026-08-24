@props(['variant' => 'primary', 'size' => 'md'])

@php
$styles = [
    'primary'   => 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500',
    'secondary' => 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-400',
    'danger'    => 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500',
    'ghost'     => 'text-slate-600 hover:bg-slate-100 focus:ring-slate-300',
];
$sizes = [
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2.5 text-sm',
    'lg' => 'px-5 py-3 text-base',
];
@endphp

<button {{ $attributes->class([
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition',
    'focus:outline-none focus:ring-2 focus:ring-offset-1',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    $styles[$variant] ?? $styles['primary'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    {{ $slot }}
</button>
