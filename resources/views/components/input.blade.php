@props(['label' => null, 'hint' => null, 'error' => null])

<div class="space-y-1.5">
    @if ($label)
        <label class="block text-sm font-medium text-slate-700" @isset($id) for="{{ $id }}" @endisset>
            {{ $label }}
        </label>
    @endif

    <input {{ $attributes->class([
        'w-full rounded-lg border px-3 py-2.5 text-slate-900 transition',
        'placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500',
        'border-slate-300' => ! $error,
        'border-rose-400 ring-1 ring-rose-200' => $error,
    ]) }}>

    @if ($error)
        <p class="text-xs text-rose-600">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
